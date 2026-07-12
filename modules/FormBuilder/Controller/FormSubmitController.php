<?php

namespace Tallyst\FormBuilder\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Intl\Countries;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Payment\MerchantOfRecordInterface;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;
use Tallyst\FormBuilder\Service\FormPaymentResolver;
use Tallyst\FormBuilder\Service\ShippingAddress;
use Tallyst\FormBuilder\Service\ShippingCatalog;
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
        private readonly TranslatorInterface $translator,
        private readonly FormPaymentResolver $paymentResolver,
        private readonly ShippingCatalog $shipping,
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

        // Shipping address (Faza 1): a PHYSICAL form that offers delivery (≥1 live method) requires the
        // standard address set, captured into the submission data under stable ship_* keys. Faza 4 K5:
        // gated on the PHYSICAL type (only physical goods ship). Folded into the SAME errors/data maps so
        // per-field errors render on the form via the existing flash mechanism, and valid values are saved.
        if ($form->getFormType()->isPhysical() && [] !== $this->shipping->offeredFor($form)) {
            foreach (array_keys(ShippingAddress::FIELDS) as $key) {
                $value = trim((string) $request->request->get($key, ''));

                // Country (Faza 2): a chosen ISO code — validate it's a real code AND allowed by this form
                // (never trust the client, same as the shipping index-gate). We store the localized NAME in
                // the submission data (readable everywhere); the stable CODE goes on the Order in startCheckout.
                if ('ship_country' === $key) {
                    $code = strtoupper($value);
                    $raw[$key] = $code; // repopulate the <select> on redirect (option values are codes)
                    if ('' === $code) {
                        $errors[$key] = $this->translator->trans('validation.shipping.address_required', [], 'validators');
                    } elseif (!Countries::exists($code)) {
                        $errors[$key] = $this->translator->trans('validation.shipping.country_invalid', [], 'validators');
                    } elseif (!$form->allowsCountry($code)) {
                        $errors[$key] = $this->translator->trans('validation.shipping.country_not_allowed', [], 'validators');
                    } else {
                        $data[$key] = Countries::getName($code, $request->getLocale());
                    }

                    continue;
                }

                $raw[$key] = $value; // repopulate on redirect
                if ('' === $value) {
                    $errors[$key] = $this->translator->trans('validation.shipping.address_required', [], 'validators');
                } else {
                    $data[$key] = $value; // captured into FormSubmission.data
                }
            }
        }

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
            ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1000))
            // A submission through a demo form inherits the demo flag (so the uninstaller removes it too);
            // through a real form it stays false — derived, never hardcoded.
            ->setIsDemo($form->isDemo());
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
        // Provider = the buyer's choice ∩ what the form actually offers (MoR-aware: a Dodo form offers
        // only Dodo — the resolver is the single source of truth, matching the front). Never a dead end.
        $available = $this->paymentResolver->offeredMethods($form);
        if ([] === $available) {
            $this->addFlash('danger', $this->translator->trans('form.payment_unavailable', [], 'messages'));

            return $this->redirect($return);
        }

        $chosen = (string) $request->request->get('payment_method', '');
        if (!in_array($chosen, $available, true)) {
            if (1 === count($available)) {
                $chosen = $available[0]; // single option → the form sends it hidden
            } else {
                $this->addFlash('danger', $this->translator->trans('form.invalid_payment_method', [], 'messages'));

                return $this->redirect($return);
            }
        }

        $processor = $this->payments->get($chosen);
        $isMerchantOfRecord = $processor instanceof MerchantOfRecordInterface;

        // Merchant-of-Record providers (Dodo) support only a FIXED price in v1.7.0 — no variant mapping yet.
        if ($isMerchantOfRecord && $form->hasVariants()) {
            $this->addFlash('danger', $this->translator->trans('form.variants_unsupported', [], 'messages'));

            return $this->redirect($return);
        }

        // A MoR form must have a linked provider product (per-form). Separate from the provider being
        // configured — a configured Dodo still can't sell a form with no product. Clear error, no 500.
        if ($isMerchantOfRecord && null === $form->getDodoProductId()) {
            $this->addFlash('danger', $this->translator->trans('form.product_not_linked', [], 'messages'));

            return $this->redirect($return);
        }

        // Or-or: variants determine the price (the chosen index is resolved server-side against the
        // defined variants — the client never sends a price); else the fixed priceMinor.
        $amountMinor = (int) $form->getPriceMinor();
        $variantLabel = null;
        if ($form->hasVariants()) {
            $raw = $request->request->get('variant');
            $variant = $form->variantAt(is_numeric($raw) ? (int) $raw : -1);
            if (null === $variant) {
                $this->addFlash('danger', $this->translator->trans('form.invalid_option', [], 'messages'));

                return $this->redirect($return);
            }
            $amountMinor = $variant['priceMinor'];
            $variantLabel = $variant['label'];
        }

        // Shipping (Faza 1) — ONLY on a PHYSICAL form (Faza 4 K5): only physical goods ship, so a digital
        // or MoR order stays unshipped (shippingLabel/Amount null, amountMinor unchanged). The buyer's
        // chosen method is resolved by INDEX against the form's offered list, with the price read from the
        // CATALOG (never the request), then folded into the gross amount BEFORE the tax split so one rate
        // covers product + delivery.
        $shippingLabel = null;
        $shippingAmount = null;
        $customerCountry = null;
        if ($form->getFormType()->isPhysical()) {
            $offered = $this->shipping->offeredFor($form);
            if ([] !== $offered) {
                $rawShipping = $request->request->get('shipping');
                $index = is_numeric($rawShipping) ? (int) $rawShipping : -1;
                if (!isset($offered[$index])) {
                    $this->addFlash('danger', $this->translator->trans('form.invalid_option', [], 'messages'));

                    return $this->redirect($return);
                }
                $shippingLabel = $offered[$index]['label'];
                $shippingAmount = $offered[$index]['priceMinor'];
                $amountMinor += $shippingAmount;

                // The buyer's delivery country as the stable ISO CODE (Faza 2). Already validated in submit()
                // (we only reach here on a valid submission); the readable NAME is in submission.data.
                $code = strtoupper(trim((string) $request->request->get('ship_country', '')));
                $customerCountry = '' !== $code ? $code : null;
            }
        }

        $order = (new Order())
            ->setForm($form)
            ->setSubmission($submission)
            ->setAmountMinor($amountMinor)
            ->setCurrency($form->getCurrency() ?: 'eur')
            ->setProvider($chosen)
            ->setPaymentMode($processor->getMode())
            ->setVariantLabel($variantLabel)
            ->setShippingLabel($shippingLabel)
            ->setShippingAmountMinor($shippingAmount)
            ->setCustomerCountry($customerCountry)
            // An order through a demo form inherits the demo flag (so the uninstaller removes it too);
            // through a real form it stays false — derived from the form, never hardcoded.
            ->setIsDemo($form->isDemo())
            // Unguessable token for the thank-you URL (?t=) — anti-enumeration.
            ->setThankYouToken(bin2hex(random_bytes(16)));

        // Tax recording (provider-agnostic): inclusive split derived from the gross amount — the
        // charged amount is unchanged. IP from the request; tax fields stay null when tax is disabled
        // (export distinguishes that). B2B data (company/VAT) is captured via ordinary form fields
        // (conditional display) → the "Podaci kupca" CSV column, not imposed checkout inputs.
        $order->setCustomerIp($request->getClientIp());
        // Merchant-of-Record providers (Dodo) are the legal seller and handle tax themselves — never
        // apply Tallyst's inclusive tax to a MoR order (it would double-count). Tax fields stay null.
        if (!$isMerchantOfRecord) {
            // Per-form resolution (Faza 3): forForm() picks the rate BY KEY from the live catalog (null =
            // no tax — master off, per-form "no tax", or empty catalog). The gross ($amountMinor, product +
            // shipping) and the inclusive formula are UNCHANGED — only the rate source moved to the form's
            // key. The resolved rate/name are snapshotted onto the order (historical — old orders untouched).
            $eff = $this->tax->forForm($form);
            if (null !== $eff) {
                $b = $this->tax->breakdown($amountMinor, (float) $eff['rate']);
                $order->setNetAmountMinor($b['net'])
                    ->setTaxAmountMinor($b['tax'])
                    ->setTaxRate((string) $eff['rate'])
                    ->setTaxName($eff['name']);
            }
        }

        $this->orders->save($order); // persist to obtain an id

        $successUrl = $this->generateUrl('form_builder_order_thankyou', ['id' => $order->getId(), 't' => $order->getThankYouToken()], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $request->getSchemeAndHttpHost().$return;

        $checkoutUrl = $processor->createCheckout($order, $successUrl, $cancelUrl);
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
