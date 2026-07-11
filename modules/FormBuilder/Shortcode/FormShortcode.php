<?php

namespace Tallyst\FormBuilder\Shortcode;

use App\Content\ShortcodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Countries;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Form\FormSchemaFactory;
use Tallyst\FormBuilder\Payment\PaymentProcessorRegistry;
use Tallyst\FormBuilder\Repository\FormDefinitionRepository;
use Tallyst\FormBuilder\Service\FormPaymentResolver;
use Tallyst\FormBuilder\Service\ShippingAddress;
use Tallyst\FormBuilder\Service\ShippingCatalog;
use Tallyst\FormBuilder\Service\TaxCalculator;
use Twig\Environment;

/**
 * Handles [form id=N]: renders the published form as HTML so render_content can
 * splice it into page content. Core stays unaware of this tag — it self-registers
 * via the app.shortcode tag (ShortcodeInterface).
 */
class FormShortcode implements ShortcodeInterface
{
    private const PROVIDER_LABELS = ['stripe' => 'Stripe', 'paypal' => 'PayPal'];

    public function __construct(
        private readonly FormDefinitionRepository $forms,
        private readonly FormSchemaFactory $schemas,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly PaymentProcessorRegistry $payments,
        private readonly TaxCalculator $tax,
        private readonly FormPaymentResolver $paymentResolver,
        private readonly ShippingCatalog $shipping,
    ) {
    }

    public function getName(): string
    {
        return 'form';
    }

    public function render(array $attributes, ?string $content = null): string
    {
        $id = (int) ($attributes['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        $form = $this->forms->findPublished($id);
        if (null === $form) {
            return \sprintf('<!-- Tallyst: form #%d not found or unpublished -->', $id);
        }

        [$errors, $old] = $this->pullFlash($id);

        // Single source of truth (FormPaymentResolver): a Merchant-of-Record form (Dodo product or a MoR
        // provider allowed) suppresses the Tallyst inclusive-tax note — the MoR collects tax itself.
        $isMerchantOfRecord = $this->paymentResolver->isMerchantOfRecordForm($form);
        // Per-form resolution (Faza 3): the note shows the form's OWN rate (by key from the live catalog),
        // so changing a rate's percentage in Settings updates the note immediately. Same gate as before
        // (product, non-MoR); forForm() also folds in the master switch + per-form "no tax" (→ null).
        $tax = ($form->isProduct() && !$isMerchantOfRecord) ? $this->tax->forForm($form) : null;
        $showTax = null !== $tax;

        // Shipping (Faza 1): offered only on a product form that ISN'T MoR — same suppression as the tax
        // note (a MoR form never shows delivery/address). offeredFor filters the form's selection against
        // the live catalog. When delivery is offered, the standard address set is required.
        $offeredShipping = ($form->isProduct() && !$isMerchantOfRecord) ? $this->shipping->offeredFor($form) : [];

        return $this->twig->render('@FormBuilder/form/render.html.twig', [
            'shipping_countries' => [] !== $offeredShipping ? $this->countryOptions($form) : [],
            'form' => $form,
            'schema' => $this->schemas->client($form),
            'errors' => $errors,
            'old' => $old,
            // MoR form → the resolver offers ONLY Dodo (never Stripe/PayPal), regardless of
            // allowedPaymentMethods (incl. empty). This closes the front gap (all methods shown).
            'payment_methods' => $form->isProduct() ? $this->paymentMethods($form) : [],
            'tax' => ['enabled' => $showTax, 'name' => $tax['name'] ?? '', 'rate' => $tax['rate'] ?? 0.0],
            'shipping' => $offeredShipping,
            'shipping_address' => [] !== $offeredShipping ? ShippingAddress::FIELDS : [],
            'currency' => strtoupper($form->getCurrency() ?: 'eur'),
        ]);
    }

    /**
     * The country options for the checkout "Country" dropdown: the form's allowed codes (Faza 2), or the
     * FULL standard list when the form has no allow-list (empty = ships everywhere). Returned as
     * code => localized name (request locale), sorted by name. Only called when delivery is offered.
     *
     * @return array<string, string>
     */
    private function countryOptions(FormDefinition $form): array
    {
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
        $allowed = $form->getAllowedShippingCountries();
        $codes = ($allowed && [] !== $allowed) ? $allowed : Countries::getCountryCodes();

        $options = [];
        foreach ($codes as $code) {
            if (Countries::exists($code)) {
                $options[$code] = Countries::getName($code, $locale);
            }
        }
        asort($options); // by localized name, for a usable dropdown

        return $options;
    }

    /**
     * The buy-button providers as {name,label} — driven by the resolver's offeredMethods (MoR-aware).
     *
     * @return array<int, array{name: string, label: string}>
     */
    private function paymentMethods(FormDefinition $form): array
    {
        $methods = [];
        foreach ($this->paymentResolver->offeredMethods($form) as $name) {
            $methods[] = ['name' => $name, 'label' => self::PROVIDER_LABELS[$name] ?? ucfirst($name)];
        }

        return $methods;
    }

    /**
     * One-time read of validation errors + old input stashed by the submit
     * controller before its redirect. Only touches the session if one already
     * exists, so plain page views never force-start a session.
     *
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    private function pullFlash(int $id): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->hasPreviousSession()) {
            return [[], []];
        }

        $bag = $request->getSession()->getFlashBag();

        /** @var array<string, string> $errors */
        $errors = $bag->get('fb_errors_'.$id)[0] ?? [];
        /** @var array<string, mixed> $old */
        $old = $bag->get('fb_old_'.$id)[0] ?? [];

        return [$errors, $old];
    }
}
