<?php

namespace Tallyst\FormBuilder\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Service\OrderPaymentSync;

/**
 * PayPal webhook — its own endpoint (PayPal verification differs from Stripe: an API call with
 * several PAYPAL-* headers + the configured webhook id, done inside PayPalProcessor). Public, no
 * CSRF (verified by signature). After verification it hands the agnostic result to the SAME
 * OrderPaymentSync as Stripe, so paid/refund go through identical idempotency guards.
 */
class PayPalWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly OrderPaymentSync $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/paypal', name: 'form_builder_webhook_paypal', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        try {
            $result = $this->payments->get('paypal')->parseSignedWebhook(
                $request->getContent(),
                $this->flattenHeaders($request),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('PayPal webhook verification failed.', ['error' => $e->getMessage()]);

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        return new Response($this->sync->apply($result), Response::HTTP_OK);
    }

    /** @return array<string, string> lowercased header name → first value */
    private function flattenHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $headers;
    }
}
