<?php

namespace Tallyst\FormBuilder\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tallyst\FormBuilder\Condition\ConditionEvaluator;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormField;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Form\FormSchemaFactory;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Repository\FormSubmissionRepository;
use Tallyst\FormBuilder\Repository\OrderRepository;

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
        private readonly FormSchemaFactory $schemas,
        private readonly ConditionEvaluator $evaluator,
        private readonly PaymentProcessorRegistry $payments,
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

        $visible = array_flip($this->evaluator->visibleKeys($this->schemas->condition($form), $raw));

        $errors = [];
        $data = [];
        foreach ($fields as $field) {
            $key = $field->getKey();
            if (!isset($visible[$key])) {
                continue; // hidden by conditions — not required, not validated, dropped
            }

            $value = $raw[$key];
            $error = $this->validateField($field, $value);
            if (null !== $error) {
                $errors[$key] = $error;
                continue;
            }

            $data[$key] = $value;
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
            ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1000));
        $this->submissions->save($submission);

        // Free form: done. Priced form: go to payment.
        if (!$form->isProduct()) {
            $separator = str_contains($return, '?') ? '&' : '?';

            return $this->redirect($return.$separator.'fb_success='.$id);
        }

        return $this->startCheckout($form, $submission, $request, $return);
    }

    #[Route('/form/order/{id}/thank-you', name: 'form_builder_order_thankyou', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function thankYou(Order $order): Response
    {
        // UX only — the real paid/fulfilled status is driven by the webhook. This
        // page may legitimately show "processing" if the webhook hasn't landed yet.
        return $this->render('@FormBuilder/form/thank_you.html.twig', ['order' => $order]);
    }

    private function startCheckout(FormDefinition $form, FormSubmission $submission, Request $request, string $return): Response
    {
        $order = (new Order())
            ->setForm($form)
            ->setSubmission($submission)
            ->setAmountMinor((int) $form->getPriceMinor())
            ->setCurrency($form->getCurrency() ?: 'eur')
            ->setProvider('stripe');
        $this->orders->save($order); // persist to obtain an id

        $successUrl = $this->generateUrl('form_builder_order_thankyou', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
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

    private function validateField(FormField $field, mixed $value): ?string
    {
        $blank = '' === $value || false === $value || null === $value;

        if ($field->isRequired() && $blank) {
            return 'Ovo polje je obavezno.';
        }

        if ($blank) {
            return null;
        }

        return match ($field->getType()) {
            FormField::TYPE_EMAIL => false === filter_var((string) $value, \FILTER_VALIDATE_EMAIL)
                ? 'Unesite ispravan e-mail.' : null,
            FormField::TYPE_NUMBER => !is_numeric((string) $value)
                ? 'Unesite broj.' : null,
            FormField::TYPE_SELECT, FormField::TYPE_RADIO => !in_array((string) $value, $field->getOptions(), true)
                ? 'Neispravan odabir.' : null,
            default => null,
        };
    }

    /** Only allow same-site path redirects (no open redirect). */
    private function safeReturn(string $return): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : '/';
    }
}
