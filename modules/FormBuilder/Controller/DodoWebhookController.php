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
 * Dodo (Merchant-of-Record) webhook — the SOLE source of truth for "paid" on a Dodo order. Public,
 * two-segment path (outside /{slug} and /admin), no CSRF (server-to-server, verified by signature).
 * Auto-routed via the FormBuilder controller dir-scan (config/routes/form_builder.yaml).
 *
 * Thin, exactly like StripeWebhookController: verify the Standard-Webhooks signature (in DodoProcessor)
 * then hand the provider-agnostic result to OrderPaymentSync, which holds the shared paid/refund
 * transitions + idempotency guards.
 *
 * ⚠ Like the Stripe/PayPal webhooks, this route MUST bypass any nginx basic-auth / maintenance page
 * (see CLAUDE.md go-live trap: a 401 here leaves the order stuck "pending" despite a successful payment).
 */
class DodoWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaymentProcessorRegistry $payments,
        private readonly OrderPaymentSync $sync,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/dodo', name: 'form_builder_webhook_dodo', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        try {
            $result = $this->payments->get('dodo')->parseSignedWebhook(
                $request->getContent(),
                $this->flattenHeaders($request),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Dodo webhook signature verification failed.', ['error' => $e->getMessage()]);

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
