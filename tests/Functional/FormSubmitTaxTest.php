<?php

namespace App\Tests\Functional;

use App\Entity\Page;
use App\Entity\Setting;
use App\Settings\SettingsManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\FakeProcessor;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;
use Tallyst\FormBuilder\Service\TaxCatalog;

/**
 * Faza 3 Komad 4 — per-form tax rate resolution through the REAL submit → startCheckout flow (fake
 * processor, no HTTP). Proves the checkout records the rate resolved BY the form's catalog KEY (not a
 * global scalar), that a percentage change on that key is reflected LIVE both at checkout and in the front
 * note (condition #1), that "no tax" and the master-off record no tax (conditions #4/#5), and that a null
 * key (backfilled default state) charges the default (backward-compat, condition #2). Needs the test DB.
 */
class FormSubmitTaxTest extends WebTestCase
{
    /** @var int[] */
    private array $formIds = [];
    /** @var int[] */
    private array $pageIds = [];

    private const PROVIDER_SETTINGS = [
        'stripe_secret_key', 'stripe_webhook_secret',
        'paypal_client_id', 'paypal_client_secret', 'paypal_webhook_id',
        'dodo_api_key', 'dodo_webhook_secret',
    ];

    /** #1 (checkout half): the order records the rate/name resolved from the form's KEY, over the gross. */
    public function testCheckoutRecordsThePerFormRate(): void
    {
        $client = static::createClient();
        $this->boot();
        $this->set('tax_enabled', '1');
        $this->seedRates([
            ['key' => 'r_std', 'name' => 'PDV', 'rate' => '25', 'default' => true],
            ['key' => 'r_red', 'name' => 'Reduced', 'rate' => '10', 'default' => false],
        ]);

        // A form pinned to the REDUCED rate (not the default) — proves resolution is by key, not "the global".
        $slug = $this->seedForm(5000, 'r_red');
        $order = $this->submit($client, $slug);

        self::assertSame(5000, $order->getAmountMinor());
        self::assertSame('10.00', $order->getTaxRate(), 'the form-key rate, not the default 25');
        self::assertSame('Reduced', $order->getTaxName());
        // Inclusive 10% over 5000: net 4545, tax 455 (sum back to gross).
        self::assertSame(4545, $order->getNetAmountMinor());
        self::assertSame(455, $order->getTaxAmountMinor());
        self::assertSame($order->getAmountMinor(), $order->getNetAmountMinor() + $order->getTaxAmountMinor());
    }

    /** #2 backward-compat: a null-key form (the backfilled/"default" state) charges the catalog default. */
    public function testNullKeyFormChargesTheDefaultRate(): void
    {
        $client = static::createClient();
        $this->boot();
        $this->set('tax_enabled', '1');
        $this->seedRates([['key' => 'r_std', 'name' => 'PDV', 'rate' => '25', 'default' => true]]);

        $slug = $this->seedForm(5000, null); // no per-form choice → default
        $order = $this->submit($client, $slug);

        self::assertSame('25.00', $order->getTaxRate());
        self::assertSame('PDV', $order->getTaxName());
        self::assertSame(4000, $order->getNetAmountMinor());
        self::assertSame(1000, $order->getTaxAmountMinor());
    }

    /**
     * #1 (the explicit condition): change an EXISTING rate's percentage in Settings and — WITHOUT touching
     * the form — both the front note AND the recorded charge switch to the new percentage immediately.
     */
    public function testLiveRateChangeHitsNoteAndCheckout(): void
    {
        $client = static::createClient();
        $this->boot();
        $this->set('tax_enabled', '1');
        $this->seedRates([['key' => 'r_std', 'name' => 'PDV', 'rate' => '25', 'default' => true]]);
        // The form is created WHILE the rate is 25% — but it stores only the KEY, never the percentage.
        $slug = $this->seedForm(5000, 'r_std');

        // Before: the front note shows 25%.
        $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('fb-tax', $html);
        self::assertStringContainsString('(25%)', $html, 'the front note shows the 25% rate');

        // Admin raises PDV 25 → 27 in Settings (SAME key). No form edit.
        $this->seedRates([['key' => 'r_std', 'name' => 'PDV', 'rate' => '27', 'default' => true]]);

        // After: the note reflects 27% immediately (live #1b) — the form was never touched.
        $client->request('GET', '/'.$slug);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('(27%)', $html, 'the note reflects the raised rate live');
        self::assertStringNotContainsString('(25%)', $html);

        // And a checkout NOW records 27% (live #1a) — proving the charge reads the live catalog by key, not
        // a percentage snapshotted when the form was built.
        $order = $this->submit($client, $slug);
        self::assertSame('27.00', $order->getTaxRate(), 'the charge reflects the raised rate live');
        self::assertSame(3937, $order->getNetAmountMinor(), 'net at 27% inclusive of 5000');
        self::assertSame(1063, $order->getTaxAmountMinor());
    }

    /** #4: taxRateKey = "no tax" → the order's tax columns stay null AND the front note is hidden. */
    public function testNoTaxSentinelRecordsNoTaxAndHidesNote(): void
    {
        $client = static::createClient();
        $this->boot();
        $this->set('tax_enabled', '1');
        $this->seedRates([['key' => 'r_std', 'name' => 'PDV', 'rate' => '25', 'default' => true]]);
        $slug = $this->seedForm(5000, FormDefinition::TAX_NONE);

        $client->request('GET', '/'.$slug);
        self::assertStringNotContainsString('fb-tax', (string) $client->getResponse()->getContent(), 'no note for a no-tax form');

        $order = $this->submit($client, $slug);
        self::assertNull($order->getTaxAmountMinor(), 'no tax recorded');
        self::assertNull($order->getNetAmountMinor());
        self::assertNull($order->getTaxRate());
        self::assertNull($order->getTaxName());
    }

    /** #5: master switch off (fresh install) → no tax anywhere, note hidden, even with a keyed form. */
    public function testMasterOffRecordsNoTax(): void
    {
        $client = static::createClient();
        $this->boot();
        $this->set('tax_enabled', '0');
        $this->seedRates([['key' => 'r_std', 'name' => 'PDV', 'rate' => '25', 'default' => true]]);
        $slug = $this->seedForm(5000, 'r_std');

        $client->request('GET', '/'.$slug);
        self::assertStringNotContainsString('fb-tax', (string) $client->getResponse()->getContent());

        $order = $this->submit($client, $slug);
        self::assertNull($order->getTaxAmountMinor());
        self::assertNull($order->getNetAmountMinor());
    }

    // --- harness ---

    private function boot(): void
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM setting WHERE name IN (?)',
            [self::PROVIDER_SETTINGS],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->set('fake_processor_enabled', '1');
    }

    /**
     * Write a setting through a FRESHLY-fetched SettingsManager. Some tests fire multiple requests (the
     * test client reboots the kernel per request), so a held service reference would use a closed EM —
     * never cache one across a request. The write is DB-backed, so the next request's kernel reads it.
     */
    private function set(string $key, string $value): void
    {
        static::getContainer()->get(SettingsManager::class)->set($key, $value);
    }

    /** @param list<array{key:string,name:string,rate:string,default:bool}> $rates */
    private function seedRates(array $rates): void
    {
        $this->set(TaxCatalog::SETTING_KEY, json_encode($rates));
    }

    private function seedForm(int $price, ?string $taxRateKey): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));

        $form = (new FormDefinition())
            ->setName('Tax test '.$suffix)
            ->setSlug('tax-test-'.$suffix)
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::DIGITAL) // priced, no shipping → a digital product (Faza 4 explicit type)
            ->setPriceMinor($price)
            ->setCurrency('eur')
            ->setAllowedPaymentMethods([FakeProcessor::NAME])
            ->setTaxRateKey($taxRateKey);
        $em->persist($form);
        $em->flush();
        $this->formIds[] = $form->getId();

        $slug = 'tax-page-'.$suffix;
        $page = (new Page('Tax '.$suffix, $slug))
            ->setStatus(Page::STATUS_PUBLISHED)
            ->setContent('[form id='.$form->getId().']');
        $em->persist($page);
        $em->flush();
        $this->pageIds[] = $page->getId();

        return $slug;
    }

    private function submit(KernelBrowser $client, string $slug): Order
    {
        // The rate limiter would reject a second submit in the same test (live-change case) — clear it.
        static::getContainer()->get('cache.rate_limiter')->clear();
        $crawler = $client->request('GET', '/'.$slug);
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('button.fb-submit')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $order = $em->getRepository(Order::class)->findOneBy(['form' => $this->formIds], ['id' => 'DESC']);
        self::assertNotNull($order, 'the submit created an order');
        $em->refresh($order);

        return $order;
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
        foreach (['fake_processor_enabled', 'tax_enabled', 'tax_rate', 'tax_name', TaxCatalog::SETTING_KEY] as $name) {
            if (null !== ($setting = $em->getRepository(Setting::class)->findOneBy(['name' => $name]))) {
                $em->remove($setting);
            }
        }
        $em->flush();
        $container->get('cache.rate_limiter')->clear();

        parent::tearDown();
    }
}
