<?php

namespace Tallyst\FormBuilder\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\SubmissionNotifier;
use Tallyst\FormBuilder\Service\SubmissionValidator;
use Tallyst\FormBuilder\Service\TaxCalculator;

/**
 * Public form submission endpoint. Two-segment path (/form/...) so the /{slug}
 * catch-all never matches it. Post/Redirect/Get on success and failure.
 *
 * Spam guard: per-IP rate limit + a honeypot field. A free form saves the
 * submission and shows success; a priced form (page-as-product) additionally
 * creates a pending Order and redirects to Stripe Checkout — but "paid" is set
 * only later by the verified webhook, never here.
 */
class FormSubmitController extends AbstractController
{
    /** Honeypot field name — leading underscore can't collide with slugified keys. */
    private const HONEYPOT = '_hp';

    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormSubmissionRepository $submissions,
        private readonly OrderRepository $orders,
        private readonly SubmissionValidator $validator,
        private readonly SubmissionNotifier $notifier,
        private readonly PaymentProcessorRegistry $payments,
        private readonly TaxCalculator $tax,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.form_submit')]
        private readonly RateLimiterFactory $formSubmitLimiter,
    ) {
    }

    #[Route('/form/{id}/submit', name: 'form_builder_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): Response
    {
        $form = $this->forms->findPublished($id);
        if (null === $form) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('form_submit_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $return = $this->safeReturn((string) $request->request->get('_return', '/'));

        // Honeypot: a bot filled the hidden field — pretend success, save nothing.
        if ('' !== trim((string) $request->request->get(self::HONEYPOT, ''))) {
            return $this->redirect($return);
        }

        // Per-IP rate limit.
        if (!$this->formSubmitLimiter->create($request->getClientIp())->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        /** @var FormField[] $fields */
        $fields = $form->getFields()->toArray();

        $raw = [];
        foreach ($fields as $field) {
            $raw[$field->getKey()] = $this->readValue($request, $field);
        }

        ['errors' => $errors, 'data' => $data] = $this->validator->validate($form, $raw);

        if ([] !== $errors) {
            $bag = $request->getSession()->getFlashBag();
            $bag->add('fb_errors_'.$id, $errors);
            $bag->add('fb_old_'.$id, $raw);

            return $this->redirect($return);
        }

        $submission = (new FormSubmission())
            ->setForm($form)
            ->setData($data)
            ->setIpAddress($request->getClientIp())
            ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1000));
        $this->submissions->save($submission);

        // Free form: done. Priced form: go to payment.
        if (!$form->isProduct()) {
            // Notify (async via $mailer->send()). A notification hiccup must never fail the
            // submitter's success — the submission is already saved.
            try {
                $this->notifier->notify($submission);
            } catch (\Throwable) {
                // swallow — best-effort notification
            }

            $separator = str_contains($return, '?') ? '&' : '?';

            return $this->redirect($return.$separator.'fb_success='.$id);
        }

        return $this->startCheckout($form, $submission, $request, $return);
    }

    #[Route('/form/order/{id}/thank-you', name: 'form_builder_order_thankyou', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function thankYou(Order $order, Request $request): Response
    {
        // Anti-enumeration: the URL must carry the order's unguessable token (?t=). A missing/wrong token
        // — or an old order with none — is a 404, so sequential ids can't reveal other orders. (Param is
        // `t`, NOT `token`: PayPal appends its own ?token=…&PayerID=… to the return URL.)
        $token = $order->getThankYouToken();
        if (null === $token || !hash_equals($token, (string) $request->query->get('t', ''))) {
            throw $this->createNotFoundException();
        }

        // Finalize the provider's return: Stripe = no-op (Checkout auto-captures); PayPal captures
        // the approved order. The webhook still drives paid/fulfilled, so this page may show
        // "processing" until it lands. A capture failure (declined/expired/abandoned) must stay
        // graceful — the order stays pending and the page shows "processing", never a 500.
        try {
            $this->payments->get($order->getProvider())->finalizeReturn($order);
            $this->orders->save($order); // persist the capture id / buyer e-mail set during capture
        } catch (\Throwable $e) {
            $this->logger->warning('Payment finalize-return failed.', ['order' => $order->getId(), 'error' => $e->getMessage()]);
        }

        return $this->render('@FormBuilder/form/thank_you.html.twig', ['order' => $order]);
    }

    private function startCheckout(FormDefinition $form, FormSubmission $submission, Request $request, string $return): Response
    {
        // Provider = the buyer's choice ∩ what's configured-and-allowed. Never a dead end / 500.
        $available = $this->payments->availableFor($form->getAllowedPaymentMethods());
        if ([] === $available) {
            $this->addFlash('danger', 'Plaćanje trenutno nije dostupno.');

            return $this->redirect($return);
        }

        $chosen = (string) $request->request->get('payment_method', '');
        if (!in_array($chosen, $available, true)) {
            if (1 === count($available)) {
                $chosen = $available[0]; // single option → the form sends it hidden
            } else {
                $this->addFlash('danger', 'Odaberite ispravan način plaćanja.');

                return $this->redirect($return);
            }
        }

        // Or-or: variants determine the price (the chosen index is resolved server-side against the
        // defined variants — the client never sends a price); else the fixed priceMinor.
        $amountMinor = (int) $form->getPriceMinor();
        $variantLabel = null;
        if ($form->hasVariants()) {
            $raw = $request->request->get('variant');
            $variant = $form->variantAt(is_numeric($raw) ? (int) $raw : -1);
            if (null === $variant) {
                $this->addFlash('danger', 'Odaberite ispravnu opciju.');

                return $this->redirect($return);
            }
            $amountMinor = $variant['priceMinor'];
            $variantLabel = $variant['label'];
        }

        $order = (new Order())
            ->setForm($form)
            ->setSubmission($submission)
            ->setAmountMinor($amountMinor)
            ->setCurrency($form->getCurrency() ?: 'eur')
            ->setProvider($chosen)
            ->setPaymentMode($this->payments->get($chosen)->getMode())
            ->setVariantLabel($variantLabel)
            // Unguessable token for the thank-you URL (?t=) — anti-enumeration.
            ->setThankYouToken(bin2hex(random_bytes(16)));

        // Tax recording (provider-agnostic): inclusive split derived from the gross amount — the
        // charged amount is unchanged. IP from the request; tax fields stay null when tax is disabled
        // (export distinguishes that). B2B data (company/VAT) is captured via ordinary form fields
        // (conditional display) → the "Podaci kupca" CSV column, not imposed checkout inputs.
        $order->setCustomerIp($request->getClientIp());
        if ($this->tax->isEnabled()) {
            $b = $this->tax->breakdown($amountMinor);
            $order->setNetAmountMinor($b['net'])
                ->setTaxAmountMinor($b['tax'])
                ->setTaxRate((string) $this->tax->rate())
                ->setTaxName($this->tax->name());
        }

        $this->orders->save($order); // persist to obtain an id

        $successUrl = $this->generateUrl('form_builder_order_thankyou', ['id' => $order->getId(), 't' => $order->getThankYouToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $request->getSchemeAndHttpHost().$return;

        $checkoutUrl = $this->payments->get($order->getProvider())->createCheckout($order, $successUrl, $cancelUrl);
        $this->orders->save($order); // persist the provider session id set during checkout

        return $this->redirect($checkoutUrl, Response::HTTP_SEE_OTHER);
    }

    private function readValue(Request $request, FormField $field): mixed
    {
        if (FormField::TYPE_CHECKBOX === $field->getType()) {
            return $request->request->has($field->getKey()) ? '1' : false;
        }

        return (string) $request->request->get($field->getKey(), '');
    }

    /** Only allow same-site path redirects (no open redirect). */
    private function safeReturn(string $return): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/';
    }
}
