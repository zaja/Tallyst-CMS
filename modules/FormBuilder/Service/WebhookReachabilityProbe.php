<?php

namespace Tallyst\FormBuilder\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * On-demand probe for the webhook 401 trap (the recurring go-live failure: basic-auth blocks the
 * webhook → payment succeeds but the order stays "U obradi", no mails). It POSTs an EMPTY, UNSIGNED
 * body to our OWN webhook URLs (built from DEFAULT_URI — the canonical host providers actually
 * call). The unsigned body fails signature verification INSIDE the controller → HTTP 400, so NO
 * order logic runs (safe). Interpretation:
 *   - 401  → PROBLEM: front basic-auth blocks the route before Symfony.
 *   - any other reachable code (incl. 400) → OK: reachable, not basic-auth-blocked.
 *   - transport error → MANUAL: couldn't reach it from here (verify manually).
 * Honest caveat surfaced to the user: a 401 can be a FALSE alarm under an IP-allowlist (this call
 * isn't from a Stripe/PayPal IP), and a self-call can fail on hairpin NAT.
 */
class WebhookReachabilityProbe
{
    private const ROUTES = [
        'Stripe' => 'form_builder_webhook_stripe',
        'PayPal' => 'form_builder_webhook_paypal',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RouterInterface $router,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{provider: string, url: string, status: string, message: string}>
     */
    public function probe(): array
    {
        $base = rtrim($this->defaultUri, '/');
        $results = [];
        foreach (self::ROUTES as $provider => $route) {
            $results[] = $this->probeOne($provider, $base.$this->router->generate($route));
        }

        return $results;
    }

    /**
     * @return array{provider: string, url: string, status: string, message: string}
     */
    private function probeOne(string $provider, string $url): array
    {
        try {
            $code = $this->httpClient->request('POST', $url, [
                'timeout' => 5,
                'max_redirects' => 0,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '',
            ])->getStatusCode();
        } catch (\Throwable $e) {
            return [
                'provider' => $provider,
                'url' => $url,
                'status' => 'manual',
                // Verdicts translate via the `admin` domain (the probe runs from the admin panel = request locale).
                'message' => $this->translator->trans('admin.form.webhook_check.verdict.error', ['%error%' => $e->getMessage(), '%url%' => $url], 'admin'),
            ];
        }

        if (401 === $code) {
            return [
                'provider' => $provider,
                'url' => $url,
                'status' => 'problem',
                'message' => $this->translator->trans('admin.form.webhook_check.verdict.blocked', [], 'admin'),
            ];
        }

        return [
            'provider' => $provider,
            'url' => $url,
            'status' => 'ok',
            'message' => $this->translator->trans('admin.form.webhook_check.verdict.ok', ['%code%' => $code], 'admin'),
        ];
    }
}
