<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\Setting;
use App\Settings\SettingsManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;
use App\Tests\Support\FakeMoRProcessor;
use App\Tests\Support\FakeProcessor;
use Tallyst\FormBuilder\Service\ShippingCatalog;

/**
 * End-to-end shipping (Faza 1) through the real submit → startCheckout flow, driven by test-only fake
 * processors (no HTTP). Covers the MoR regression (the most important test: a Dodo/MoR order is
 * untouched — no shipping, no tax, amount unchanged), the non-MoR amount summation (amount = product +
 * delivery; one tax rate covers both), the invalid-index guard, and the required-address capture.
 */
class FormSubmitShippingTest extends WebTestCase
{
    /** @var int[] */
    private array $formIds = [];
    /** @var int[] */
    private array $pageIds = [];

    /**
     * Real provider config any other test may have left in the shared DB. Encrypted secrets can't be
     * cleared through SettingsManager (an empty write is a no-op — write-only guard), so those tests'
     * "cleanup" leaves them SET. We delete the rows directly so the ONLY configured providers here are our
     * fakes (gated by fake_processor_enabled) — otherwise a leaked Dodo would make a MoR form offer TWO MoR
     * methods (radios instead of a hidden input) and the submit would carry no chosen method.
     */
    private const PROVIDER_SETTINGS = [
        'stripe_secret_key', 'stripe_webhook_secret',
        'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id',
        'dodo_api_key', 'dodo_webhook_secret',
    ];

    private function clearProviderSettings(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM setting WHERE name IN (?)',
            [self::PROVIDER_SETTINGS],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        // The submit endpoint is per-IP rate-limited (filesystem cache). Left un-cleared, submits from a
        // prior run/test for 127.0.0.1 exhaust the bucket → later submits are rejected (no order) and the
        // order-asserting tests flake. Clear the bucket so each test starts fresh (same as LoginThrottlingTest).
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    /**
     * MoR REGRESSION (run this first): a Merchant-of-Record form — even with shipping configured AND tax
     * enabled — produces an order with NO shipping and NO Tallyst tax, amount unchanged. Proves the
     * startCheckout shipping block is entirely on the non-MoR path.
     */
    public function testMerchantOfRecordOrderIsUnchangedByShipping(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings(); // hermetic: no leaked real provider config
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $settings->set('tax_enabled', '1');
        $settings->set('tax_rate', '25');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);

        // A MoR form (dodoProductId → MoR) that ALSO has shipping configured — the gate must still skip it.
        $slug = $this->seedProductForm($em, [
            'price' => 2000,
            'allowed' => [FakeMoRProcessor::NAME],
            'dodoProductId' => 'fake_prod_123',
            'shipping' => [$shipKey],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        // MoR front suppression: no delivery selection, no delivery address rendered.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('name="shipping"', $html, 'a MoR form shows no delivery selection');
        self::assertStringNotContainsString('name="ship_name"', $html, 'a MoR form shows no delivery address');

        $client->submit($crawler->filter('button.fb-submit')->form());

        $order = $this->latestOrder($em);
        self::assertNotNull($order, 'a MoR order was created');
        self::assertSame(FakeMoRProcessor::NAME, $order->getProvider());
        self::assertSame(2000, $order->getAmountMinor(), 'amount is unchanged — no shipping added on the MoR path');
        self::assertNull($order->getShippingLabel(), 'no shipping label on a MoR order');
        self::assertNull($order->getShippingAmountMinor(), 'no shipping amount on a MoR order');
        self::assertNull($order->getTaxAmountMinor(), 'Tallyst tax is not applied to a MoR order');
        self::assertNull($order->getNetAmountMinor());
    }

    /**
     * MoR MONEY PATH (Faza 6 K4): a MoR form with TWO sellable units — the buyer picks index 1 → the order
     * records THAT unit's id (createCheckout charges it), its label (a choice WAS offered → >1), and its
     * display price. The K5 front will render the radios; here we send the chosen index directly (the server
     * resolves it — the client never sends a price/id).
     */
    public function testMoRMultiUnitOrderChargesTheChosenUnit(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        $slug = $this->seedProductForm($em, [
            'price' => 0,
            'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [
                ['label' => 'Personal', 'unitId' => 'pdt_personal', 'priceMinor' => 2900, 'currency' => 'eur'],
                ['label' => 'Team', 'unitId' => 'pdt_team', 'priceMinor' => 4900, 'currency' => 'eur'],
            ],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('button.fb-submit')->form();
        $values = $form->getPhpValues();
        $values['variant'] = '1'; // the buyer chose "Team"
        $client->request($form->getMethod(), $form->getUri(), $values);

        $order = $this->latestOrder($em);
        self::assertNotNull($order);
        self::assertSame('pdt_team', $order->getProviderUnitId(), 'the chosen unit id → createCheckout charges it');
        self::assertSame('Team', $order->getVariantLabel(), 'the chosen option label (a choice was offered)');
        self::assertSame(4900, $order->getAmountMinor(), 'the chosen unit display price is recorded');
    }

    /**
     * MoR FRONT (Faza 6 K5): a form with >1 units renders the choice (radios with per-unit prices) + the
     * provider-charges note, and HIDES the form price (form.priceMinor) — the buyer sees prices per option.
     */
    public function testMoRMultiUnitFrontShowsTheChoiceAndHidesFormPrice(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        $slug = $this->seedProductForm($em, [
            'price' => 9900, // a form price is set — but it must NOT show for a >1-unit MoR form
            'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [
                ['label' => 'Personal', 'unitId' => 'pdt_personal', 'priceMinor' => 2900, 'currency' => 'eur'],
                ['label' => 'Team', 'unitId' => 'pdt_team', 'priceMinor' => 4900, 'currency' => 'eur'],
            ],
        ]);

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('name="variant"', $html, 'a >1-unit MoR form renders the choice');
        self::assertStringContainsString('Personal', $html);
        self::assertStringContainsString('Team', $html);
        self::assertStringContainsString('29,00', $html, 'per-unit display price is shown');
        self::assertStringContainsString('49,00', $html);
        self::assertStringNotContainsString('class="fb-price"', $html, 'the form price line is hidden when there is a choice');
        self::assertStringContainsString('fb-mor-note', $html, 'the provider-charges note is shown with the choice');
    }

    /**
     * MoR FRONT single-unit (Faza 6 K5): exactly one unit → NO choice rendered (works as before), and the
     * form price line IS shown.
     */
    public function testMoRSingleUnitFrontShowsNoChoice(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        $slug = $this->seedProductForm($em, [
            'price' => 3900,
            'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Standard', 'unitId' => 'pdt_only', 'priceMinor' => 3900, 'currency' => 'eur']],
        ]);

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('name="variant"', $html, 'a single-unit MoR form shows no choice');
        self::assertStringContainsString('class="fb-price"', $html, 'the form price line is shown for a single unit');
    }

    /** Faza 8: a tax-EXCLUSIVE MoR unit → the short "Tax is added at checkout." note — on SINGLE too (the hole
     *  that only >1 forms had a note is now closed), and once for a MULTI-option form when ANY unit is exclusive. */
    public function testMoRExclusiveTaxNoteShownOnSingleAndMulti(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        // SINGLE-unit, tax-exclusive → the note appears (previously no note at all on single).
        $single = $this->seedProductForm($em, [
            'price' => 3900, 'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Standard', 'unitId' => 'pdt_x', 'priceMinor' => 3900, 'currency' => 'eur', 'taxInclusive' => false]],
        ]);
        $client->request('GET', '/'.$single);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tax is added at checkout.', $html, 'exclusive note on a single-unit MoR form');
        // Count the ACTUAL <p> (class="fb-mor-note"), not the string 'fb-mor-note' — the latter also appears in the CSS.
        self::assertSame(1, substr_count($html, 'class="fb-mor-note"'), 'the note is shown exactly once');

        // MULTI-unit, tax-exclusive on ONE → the exclusive note wins (buyer pays more).
        $multi = $this->seedProductForm($em, [
            'price' => 0, 'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [
                ['label' => 'A', 'unitId' => 'pdt_a', 'priceMinor' => 2900, 'currency' => 'eur', 'taxInclusive' => true],
                ['label' => 'B', 'unitId' => 'pdt_b', 'priceMinor' => 4900, 'currency' => 'eur', 'taxInclusive' => false],
            ],
        ]);
        $client->request('GET', '/'.$multi);
        $mhtml = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tax is added at checkout.', $mhtml, 'exclusive note when any unit is exclusive');
        self::assertSame(1, substr_count($mhtml, 'class="fb-mor-note"'), 'once for the whole multi-option form');
    }

    /** Faza 8: a tax-INCLUSIVE MoR unit → the plain "Price includes tax." note; a LOCALISED-price one adds the
     *  "may adjust to your region" wording; neither says "added at checkout". */
    public function testMoRInclusiveTaxNotePlainAndLocalised(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        // Plain inclusive (pricingMode null) → "Price includes tax." with no region wording.
        $plain = $this->seedProductForm($em, [
            'price' => 3900, 'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Standard', 'unitId' => 'pdt_i', 'priceMinor' => 3900, 'currency' => 'eur', 'taxInclusive' => true]],
        ]);
        $client->request('GET', '/'.$plain);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Price includes tax.', $html);
        self::assertStringNotContainsString('added at checkout', $html, 'inclusive → no exclusive wording');
        self::assertStringNotContainsString('adjust to your region', $html, 'standard pricing → no region wording');

        // Localised inclusive (pricingMode by_currency) → the "may adjust to your region" variant.
        $localised = $this->seedProductForm($em, [
            'price' => 3900, 'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Std', 'unitId' => 'pdt_l', 'priceMinor' => 3900, 'currency' => 'eur', 'taxInclusive' => true, 'pricingMode' => 'by_currency']],
        ]);
        $client->request('GET', '/'.$localised);
        self::assertStringContainsString('may adjust to your region', (string) $client->getResponse()->getContent());
    }

    /** Faza 8: an UNKNOWN tax flag (null — old / unrefreshed form) → NO note at all (an empty price claim only
     *  confuses; the previous "neutral note" was dropped). */
    public function testMoRUnknownTaxShowsNoNote(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        // No taxInclusive key → setMorUnits stores null → the front stays silent.
        $slug = $this->seedProductForm($em, [
            'price' => 3900, 'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Standard', 'unitId' => 'pdt_u', 'priceMinor' => 3900, 'currency' => 'eur']],
        ]);
        $client->request('GET', '/'.$slug);
        $html = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        // The CSS block always defines .fb-mor-note; assert the actual <p> (class="…") is absent.
        self::assertStringNotContainsString('class="fb-mor-note"', $html, 'unknown tax → no note');
    }

    /**
     * MoR single-unit (Faza 6 K4): no buyer choice → the order records the unit id but NO variant label
     * (identical to before Faza 6 — a single-product MoR order). Proves the ">1 → label" rule.
     */
    public function testMoRSingleUnitOrderHasNoVariantLabel(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('fake_processor_enabled', '1');

        $slug = $this->seedProductForm($em, [
            'price' => 0,
            'allowed' => [FakeMoRProcessor::NAME],
            'morUnits' => [['label' => 'Standard', 'unitId' => 'pdt_only', 'priceMinor' => 3900, 'currency' => 'eur']],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('button.fb-submit')->form());

        $order = $this->latestOrder($em);
        self::assertNotNull($order);
        self::assertSame('pdt_only', $order->getProviderUnitId());
        self::assertNull($order->getVariantLabel(), 'a single-unit MoR order has no variant label');
        self::assertSame(3900, $order->getAmountMinor());
    }

    /** Non-MoR: amount = product + delivery, and one inclusive tax rate covers the whole (product+shipping). */
    public function testNonMoROrderAddsShippingAndTaxesTheWhole(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings(); // hermetic: no leaked real provider config
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $settings->set('tax_enabled', '1');
        $settings->set('tax_rate', '25');
        $shipKey = $this->seedCatalog($settings, 'Express', 500);

        $slug = $this->seedProductForm($em, [
            'price' => 2000,
            'allowed' => [FakeProcessor::NAME],
            'shipping' => [$shipKey],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        // A delivery form renders the shipping selection + the required address set.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('name="shipping"', $html);
        self::assertStringContainsString('name="ship_name"', $html);

        // Single shipping method → rendered as a hidden input (index 0); single payment method → hidden too.
        // Fill the required address set.
        $client->submit($crawler->filter('button.fb-submit')->form([
            'ship_name' => 'Ana Anić',
            'ship_address' => 'Ilica 1',
            'ship_city' => 'Zagreb',
            'ship_postal' => '10000',
            'ship_country' => 'HR',
        ]));

        $order = $this->latestOrder($em);
        self::assertNotNull($order);
        self::assertSame(FakeProcessor::NAME, $order->getProvider());
        self::assertSame(2500, $order->getAmountMinor(), 'product 2000 + shipping 500');
        self::assertSame('Express', $order->getShippingLabel());
        self::assertSame(500, $order->getShippingAmountMinor());
        // Inclusive tax over the WHOLE 2500 at 25%: net 2000, tax 500 (they sum back to gross).
        self::assertSame(2000, $order->getNetAmountMinor());
        self::assertSame(500, $order->getTaxAmountMinor());
        self::assertSame($order->getNetAmountMinor() + $order->getTaxAmountMinor(), $order->getAmountMinor());

        // The address landed in the submission data.
        $data = $order->getSubmission()?->getData() ?? [];
        self::assertSame('Ana Anić', $data['ship_name'] ?? null);
        self::assertSame('Zagreb', $data['ship_city'] ?? null);
        // Country storage (Faza 2): the NAME goes into the submission data (readable everywhere), the stable
        // CODE goes on the order. The name is localized (en/hr) — never the raw code.
        self::assertSame('HR', $order->getCustomerCountry(), 'the stable ISO code is stored on the order');
        self::assertContains($data['ship_country'] ?? null, ['Croatia', 'Hrvatska'], 'the localized country NAME is stored in the submission data');
    }

    /**
     * Faza 4 K5: shipping/countries are for PHYSICAL forms only. A DIGITAL form that carries a (stray)
     * shipping method still offers NO delivery on the front — the type gate ignores it.
     */
    public function testDigitalFormNeverOffersShipping(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Express', 500);

        $suffix = bin2hex(random_bytes(5));
        $form = (new FormDefinition())
            ->setName('Dig '.$suffix)->setSlug('dig-'.$suffix)
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::DIGITAL) // digital, yet carrying a shipping method
            ->setPriceMinor(2000)->setCurrency('eur')
            ->setAllowedPaymentMethods([FakeProcessor::NAME])
            ->setShippingMethods([$shipKey]);
        $em->persist($form);
        $em->flush();
        $this->formIds[] = $form->getId();

        $slug = 'dig-page-'.$suffix;
        $page = (new Page('Dig '.$suffix, $slug))->setStatus(Page::STATUS_PUBLISHED)->setContent('[form id='.$form->getId().']');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('name="shipping"', $html, 'a digital form offers no delivery');
        self::assertStringNotContainsString('name="ship_name"', $html, 'a digital form asks for no address');
    }

    /** A tampered (out-of-range) shipping index is rejected server-side — no order, never a 500. */
    public function testInvalidShippingIndexIsRejected(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings(); // hermetic: no leaked real provider config
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, [
            'price' => 2000,
            'allowed' => [FakeProcessor::NAME],
            'shipping' => [$shipKey],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        // Only index 0 exists; force an out-of-range index (address filled so only the index fails).
        $client->submit($crawler->filter('button.fb-submit')->form([
            'shipping' => '99',
            'ship_name' => 'Ana', 'ship_address' => 'Ilica 1', 'ship_city' => 'Zagreb',
            'ship_postal' => '10000', 'ship_country' => 'HR',
        ]));

        self::assertNull($this->latestOrder($em), 'an out-of-range shipping index must not create an order');
    }

    /** A required address field left empty blocks the submit — no order, no submission. */
    public function testMissingAddressBlocksSubmit(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings(); // hermetic: no leaked real provider config
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, [
            'price' => 2000,
            'allowed' => [FakeProcessor::NAME],
            'shipping' => [$shipKey],
        ]);

        $crawler = $client->request('GET', '/'.$slug);
        // Leave ship_city empty → blocked.
        $client->submit($crawler->filter('button.fb-submit')->form([
            'ship_name' => 'Ana',
            'ship_address' => 'Ilica 1',
            'ship_city' => '',
            'ship_postal' => '10000',
            'ship_country' => 'HR',
        ]));

        self::assertNull($this->latestOrder($em), 'a missing required address field must block the order');
    }

    // --- Faza 2: shipping countries ---

    /** No allow-list = ships everywhere: the checkout offers the full country list and accepts any valid one. */
    public function testEmptyAllowListAcceptsAnyCountry(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, ['price' => 2000, 'allowed' => [FakeProcessor::NAME], 'shipping' => [$shipKey]]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        // The unrestricted dropdown carries the full standard list.
        self::assertGreaterThan(0, $crawler->filter('select[name="ship_country"] option[value="US"]')->count());
        self::assertGreaterThan(0, $crawler->filter('select[name="ship_country"] option[value="HR"]')->count());

        $client->submit($crawler->filter('button.fb-submit')->form([
            'ship_name' => 'John Doe', 'ship_address' => '1 Main St', 'ship_city' => 'NYC',
            'ship_postal' => '10001', 'ship_country' => 'US',
        ]));

        $order = $this->latestOrder($em);
        self::assertNotNull($order);
        self::assertSame('US', $order->getCustomerCountry(), 'any valid country is accepted when unrestricted');
    }

    /** A restricted form offers ONLY its allowed countries and accepts one of them. */
    public function testRestrictedListOffersOnlyAllowedCountries(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, ['price' => 2000, 'allowed' => [FakeProcessor::NAME], 'shipping' => [$shipKey], 'countries' => ['HR', 'DE']]);

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('select[name="ship_country"] option[value="HR"]')->count());
        self::assertGreaterThan(0, $crawler->filter('select[name="ship_country"] option[value="DE"]')->count());
        self::assertSame(0, $crawler->filter('select[name="ship_country"] option[value="US"]')->count(), 'a non-allowed country is not offered');

        $client->submit($crawler->filter('button.fb-submit')->form([
            'ship_name' => 'Hans', 'ship_address' => 'Hauptstr 1', 'ship_city' => 'Berlin',
            'ship_postal' => '10115', 'ship_country' => 'DE',
        ]));

        $order = $this->latestOrder($em);
        self::assertNotNull($order);
        self::assertSame('DE', $order->getCustomerCountry());
    }

    /** A tampered POST with a country outside the allow-list is blocked server-side — no order. */
    public function testDisallowedCountryIsBlocked(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, ['price' => 2000, 'allowed' => [FakeProcessor::NAME], 'shipping' => [$shipKey], 'countries' => ['HR']]);

        $crawler = $client->request('GET', '/'.$slug);
        $this->postForgedCountry($client, $crawler, (int) end($this->formIds), 'US'); // US not allowed

        self::assertNull($this->latestOrder($em), 'a disallowed country must not create an order');
    }

    /** A tampered POST with a non-existent ISO code is rejected server-side — no order. */
    public function testInvalidCountryCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $shipKey = $this->seedCatalog($settings, 'Standard', 500);
        $slug = $this->seedProductForm($em, ['price' => 2000, 'allowed' => [FakeProcessor::NAME], 'shipping' => [$shipKey]]);

        $crawler = $client->request('GET', '/'.$slug);
        $this->postForgedCountry($client, $crawler, (int) end($this->formIds), 'XX'); // not a real code

        self::assertNull($this->latestOrder($em), 'an invalid ISO code must not create an order');
    }

    /** A digital (no-delivery) form shows no country dropdown and no address at all. */
    public function testDigitalFormHasNoCountryDropdown(): void
    {
        $client = static::createClient();
        $this->clearProviderSettings();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = static::getContainer()->get(SettingsManager::class);

        $settings->set('fake_processor_enabled', '1');
        $slug = $this->seedProductForm($em, ['price' => 2000, 'allowed' => [FakeProcessor::NAME]]); // no shipping

        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('select[name="ship_country"]')->count(), 'no country dropdown without delivery');
        self::assertSame(0, $crawler->filter('[name="ship_name"]')->count(), 'no address set without delivery');
    }

    // --- helpers ---

    /** Direct POST to the submit endpoint (bypasses the client-side <select>) to test the server country gate. */
    private function postForgedCountry(KernelBrowser $client, Crawler $crawler, int $formId, string $country): void
    {
        $attr = static fn (string $name): string => $crawler->filter('[name="'.$name.'"]')->count()
            ? (string) $crawler->filter('[name="'.$name.'"]')->attr('value')
            : '';

        $client->request('POST', '/form/'.$formId.'/submit', [
            '_token' => $attr('_token'),
            '_return' => $attr('_return'),
            'payment_method' => $attr('payment_method'),
            'shipping' => '0',
            'ship_name' => 'Ana', 'ship_address' => 'Ilica 1', 'ship_city' => 'Zagreb',
            'ship_postal' => '10000', 'ship_country' => $country,
        ]);
    }

    private function seedCatalog(SettingsManager $settings, string $label, int $priceMinor): string
    {
        $key = 'ship'.bin2hex(random_bytes(2));
        $settings->set(ShippingCatalog::SETTING_KEY, json_encode([
            ['key' => $key, 'label' => $label, 'priceMinor' => $priceMinor],
        ]));

        return $key;
    }

    /**
     * @param array{price:int, allowed:string[], shipping?:string[], dodoProductId?:string, countries?:string[]} $opts
     */
    private function seedProductForm(EntityManagerInterface $em, array $opts): string
    {
        $suffix = bin2hex(random_bytes(5));

        // Faza 4: the explicit type replaces the old guessing — declare it from the intended shape
        // (Dodo/MoR units → MoR; shipping → physical; else digital), matching what the backfill would assign.
        $formType = (isset($opts['dodoProductId']) || isset($opts['morUnits']))
            ? FormType::DIGITAL_MOR
            : ([] !== ($opts['shipping'] ?? []) ? FormType::PHYSICAL : FormType::DIGITAL);

        $form = (new FormDefinition())
            ->setName('Ship test '.$suffix)
            ->setSlug('ship-test-'.$suffix)
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType($formType)
            ->setPriceMinor($opts['price'])
            ->setCurrency('eur')
            ->setAllowedPaymentMethods($opts['allowed'])
            ->setShippingMethods($opts['shipping'] ?? null)
            ->setAllowedShippingCountries($opts['countries'] ?? null);
        if (isset($opts['dodoProductId'])) {
            $form->setDodoProductId($opts['dodoProductId']);
        }
        if (isset($opts['morUnits'])) {
            $form->setMorUnits($opts['morUnits']);
        }
        // Faza 5: a MoR form records WHICH provider — here the MoR provider is the one in `allowed`
        // (the fake MoR processor). The resolver reads morProvider to offer that provider.
        if (FormType::DIGITAL_MOR === $formType) {
            $form->setMorProvider($opts['allowed'][0] ?? null);
        }
        $em->persist($form);
        $em->flush();
        $this->formIds[] = $form->getId();

        $slug = 'ship-page-'.$suffix;
        $page = (new Page('Ship '.$suffix, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('[form id='.$form->getId().']');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        return $slug;
    }

    private function latestOrder(EntityManagerInterface $em): ?Order
    {
        return $em->getRepository(Order::class)->findOneBy(['form' => $this->formIds], ['id' => 'DESC']);
    }

    protected function tearDown(): void
    {
        $container = static::getContainer();
        /** @var Connection $conn */
        $conn = $container->get(Connection::class);

        foreach ($this->formIds as $formId) {
            $conn->executeStatement('DELETE FROM fb_order WHERE form_id = ?', [$formId]);
            $conn->executeStatement('DELETE FROM fb_submission WHERE form_id = ?', [$formId]);
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$formId]);
        }
        foreach ($this->pageIds as $pageId) {
            $conn->executeStatement('DELETE FROM page WHERE id = ?', [$pageId]);
        }
        $this->formIds = $this->pageIds = [];

        $em = $container->get(EntityManagerInterface::class);
        foreach (['fake_processor_enabled', 'tax_enabled', 'tax_rate', ShippingCatalog::SETTING_KEY] as $name) {
            if (null !== ($setting = $em->getRepository(Setting::class)->findOneBy(['name' => $name]))) {
                $em->remove($setting);
            }
        }
        $em->flush();

        // Leave the per-IP rate-limiter bucket clean so this file's submits don't poison other test files.
        $container->get('cache.rate_limiter')->clear();

        parent::tearDown();
    }
}
