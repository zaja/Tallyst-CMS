<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Service\ShippingCatalog;

/**
 * Renders the form builder (new form) end-to-end. This exercises the field-row PROTOTYPE
 * (form.fields.vars.prototype), whose `vars.value` is null — so it guards the collapsible-row
 * template's null-safe summary against a dev strict_variables 500.
 */
class FormBuilderBuilderRenderTest extends WebTestCase
{
    /** @var string[] */
    private array $createdEmails = [];
    private ?int $formId = null;

    public function testBuilderPageRendersWithCollapsibleFieldPrototype(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // /new is the wizard now; the BUILDER is an existing form's edit page.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('B '.bin2hex(random_bytes(4)))->setSlug('b-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $client->request('GET', '/admin/forms/'.$this->formId.'/edit');

        self::assertResponseIsSuccessful();
        // The collapsed-row summary markup is present (carried in the field prototype).
        self::assertStringContainsString('fb-row-summary', (string) $client->getResponse()->getContent());
    }

    public function testBuilderOffersShippingMethodsFromTheCatalog(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // Seed one catalog method; the builder's shipping choice list is built from the catalog.
        $catalog = static::getContainer()->get(ShippingCatalog::class);
        $catalog->save([['key' => 'std12345', 'label' => 'Standard delivery', 'price' => '4.90']]);

        // A PHYSICAL form's builder shows the shipping choices from the catalog.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Ship '.bin2hex(random_bytes(4)))->setSlug('ship-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)->setFormType(FormType::PHYSICAL)->setPriceMinor(2000)->setCurrency('eur');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        // A checkbox whose value is the catalog KEY (not the price) — the form stores keys.
        self::assertGreaterThan(
            0,
            $crawler->filter('input[name="form_definition[shippingMethods][]"][value="std12345"]')->count(),
            'the catalog method renders as a per-form shipping checkbox keyed by its stable key',
        );
        // The checkbox label shows the price from the catalog ("… — 4,90 EUR"), display-only.
        self::assertStringContainsString('Standard delivery — 4,90 EUR', (string) $client->getResponse()->getContent());
    }

    public function testMoRFormHidesTheShippingBlock(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $catalog = static::getContainer()->get(ShippingCatalog::class);
        $catalog->save([['key' => 'std12345', 'label' => 'Standard delivery', 'price' => '4.90']]);

        // A MoR form (formType DIGITAL_MOR) → the whole Delivery-methods field is omitted from the builder.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('MoR ship '.bin2hex(random_bytes(4)))
            ->setSlug('mor-ship-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::DIGITAL_MOR)
            ->setMorProvider('dodo')
            ->setPriceMinor(2000)
            ->setCurrency('eur')
            ->setDodoProductId('prod_fake_123');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        // Faza 4 K3: fields ALWAYS in the DOM (so switching type never clears data) — a MoR form HIDES the
        // shipping/countries/tax/self-payment blocks (type-driven d-none) and SHOWS the Dodo product block.
        self::assertGreaterThan(0, $crawler->filter('input[name="form_definition[shippingMethods][]"]')->count(), 'the shipping field stays in the DOM (round-trips)');
        self::assertGreaterThan(0, $crawler->filter('.fb-subblock.d-none[data-formbuilder--formtype-target="shipping"]')->count(), 'shipping block hidden on a MoR form');
        self::assertGreaterThan(0, $crawler->filter('.d-none[data-formbuilder--formtype-target="countries"]')->count(), 'countries block hidden on a MoR form');
        self::assertGreaterThan(0, $crawler->filter('.fb-subblock.d-none[data-formbuilder--formtype-target="tax"]')->count(), 'tax block hidden on a MoR form');
        self::assertGreaterThan(0, $crawler->filter('.fb-payment-methods.d-none[data-formbuilder--formtype-target="selfPayment"]')->count(), 'Stripe/PayPal hidden on a MoR form');
        self::assertSame(0, $crawler->filter('.fb-dodo-product.d-none')->count(), 'the Dodo product block IS shown on a MoR form');
    }

    public function testPhysicalFormLocksTypeAndExcludesDodo(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Phys '.bin2hex(random_bytes(4)))->setSlug('phys-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)->setFormType(FormType::PHYSICAL)->setPriceMinor(2000)->setCurrency('eur');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        // Type is LOCKED for a physical form → the select offers exactly one option (itself).
        self::assertSame(1, $crawler->filter('select[name="form_definition[formType]"] option')->count(), 'physical type is locked');
        // Dodo (MoR) is never a self-billed method choice on a non-MoR form; Stripe/PayPal are.
        self::assertSame(0, $crawler->filter('input[name="form_definition[allowedPaymentMethods][]"][value="dodo"]')->count(), 'no Dodo method on a physical form');
        self::assertGreaterThan(0, $crawler->filter('input[name="form_definition[allowedPaymentMethods][]"][value="stripe"]')->count(), 'Stripe is offered');
    }

    public function testDigitalFormTypeSelectOffersThePair(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Dig '.bin2hex(random_bytes(4)))->setSlug('dig-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)->setFormType(FormType::DIGITAL)->setPriceMinor(2000)->setCurrency('eur');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        // A digital form CAN switch to Merchant-of-Record → the select offers both options.
        self::assertSame(2, $crawler->filter('select[name="form_definition[formType]"] option')->count(), 'digital ↔ digital_mor is switchable');
    }

    public function testDraftPlaceholderSlugFollowsTheRealName(): void
    {
        // A wizard draft (placeholder name + "untitled-form" slug) → the slug follows once renamed.
        self::assertSame('my-product', $this->saveWithName('Untitled form', 'untitled-form', false, 'My Product'));
    }

    public function testManuallySetSlugIsNotTouched(): void
    {
        // The slug isn't the auto placeholder → the admin set it → leave it (unique-suffix aside).
        self::assertSame('my-chosen-slug', $this->saveWithName('Untitled form', 'my-chosen-slug', false, 'Renamed'));
    }

    public function testPublishedSlugIsNotTouched(): void
    {
        // Published → never auto-change the slug (live links / [form id=N]).
        self::assertSame('untitled-form', $this->saveWithName('Untitled form', 'untitled-form', true, 'Renamed'));
    }

    /** Create a form, open the builder, rename it, save → return the persisted slug. */
    private function saveWithName(string $name, string $slug, bool $published, string $newName): string
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName($name)->setSlug($slug)
            ->setStatus($published ? FormDefinition::STATUS_PUBLISHED : FormDefinition::STATUS_DRAFT)
            ->setFormType(FormType::MESSAGES);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        $formNode = $crawler->filter('form')->first()->form();
        $formNode['form_definition[name]'] = $newName;
        $client->submit($formNode);
        self::assertResponseRedirects();

        $em->clear();

        return $em->getRepository(FormDefinition::class)->find($this->formId)->getSlug();
    }

    public function testDodoFormWithUnverifiableUnitSavesWithWarning(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // A Dodo form with a MANUALLY-typed unit id. Dodo is unconfigured in the test → the save-time guard
        // can't verify the unit → it must NOT block the save (only warn). Faza 6 K3 (per-unit).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Dodo save '.bin2hex(random_bytes(4)))->setSlug('dodo-save-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)->setFormType(FormType::DIGITAL_MOR)->setMorProvider('dodo')
            ->setPriceMinor(2000)->setCurrency('eur')
            ->setMorUnits([['label' => 'Standard', 'unitId' => 'manual_prod_id', 'priceMinor' => 2000, 'currency' => 'eur']]);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form')->first()->form());

        // Unverifiable unit → save PROCEEDS (redirect), never blocked.
        self::assertResponseRedirects();
    }

    public function testMorFormWithSubscriptionUnitIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // A MoR form (fakemor provider) with a "subscription" unit (id sub_*) → the per-unit save guard
        // REJECTS the whole save: no redirect, the builder re-renders with the error. Faza 6 K3.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Sub reject '.bin2hex(random_bytes(4)))->setSlug('sub-reject-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_DRAFT)->setFormType(FormType::DIGITAL_MOR)->setMorProvider('fakemor')
            ->setMorUnits([['label' => 'Monthly', 'unitId' => 'sub_1', 'priceMinor' => 900, 'currency' => 'eur']]);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filter('form')->first()->form());

        // Rejected → the page re-renders (200), NOT a redirect.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.invalid-feedback, .form-error-message, .alert', 'Monthly');
    }

    public function testMorFormMirrorsDodoProductIdFromFirstUnit(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // Faza 6 K3 transitional mirror: saving the builder sets the legacy dodoProductId = the FIRST unit's
        // id, so the still-unchanged front/checkout behave identically for a single-unit form. Two ok_* units
        // (fakemor accepts them) → save succeeds → dodoProductId mirrors the first.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Mirror '.bin2hex(random_bytes(4)))->setSlug('mirror-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_DRAFT)->setFormType(FormType::DIGITAL_MOR)->setMorProvider('fakemor')
            ->setMorUnits([
                ['label' => 'Personal', 'unitId' => 'ok_first', 'priceMinor' => 2900, 'currency' => 'eur'],
                ['label' => 'Team', 'unitId' => 'ok_second', 'priceMinor' => 4900, 'currency' => 'eur'],
            ]);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        // No raw i18n keys leak in the MoR builder (K3.5 — the mor_units help key was misplaced once).
        self::assertStringNotContainsString('admin.form.builder', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form')->first()->form());
        self::assertResponseRedirects();

        $em->clear();
        $saved = $em->getRepository(FormDefinition::class)->find($this->formId);
        self::assertCount(2, $saved->getMorUnits(), 'both units are stored');
        self::assertSame('ok_first', $saved->getDodoProductId(), 'dodoProductId mirrors the first unit');
    }

    public function testCountryPickerShownWhenFormOffersDelivery(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $catalog = static::getContainer()->get(ShippingCatalog::class);
        $catalog->save([['key' => 'std12345', 'label' => 'Standard delivery', 'price' => '4.90']]);

        // A PHYSICAL form → the shipping + country pickers are shown (type-driven).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('Ship country '.bin2hex(random_bytes(4)))
            ->setSlug('ship-country-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::PHYSICAL)
            ->setPriceMinor(2000)->setCurrency('eur')
            ->setShippingMethods(['std12345']);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        // The CountryType checkbox list (from symfony/intl) renders, keyed by ISO alpha-2 code.
        self::assertGreaterThan(0, $crawler->filter('input[name="form_definition[allowedShippingCountries][]"][value="HR"]')->count(), 'country checkboxes render from the standard list');
        // The usable-UI wrapper: search + EU preset via the Stimulus controller.
        self::assertGreaterThan(0, $crawler->filter('[data-controller="formbuilder--country-select"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-action="formbuilder--country-select#selectEu"]')->count());
    }

    public function testCountryPickerPresentButHiddenByDefault(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // A DIGITAL product form (no shipping): the country field is ALWAYS in the DOM (so switching to
        // physical reveals it without a reload), but its block starts hidden (d-none) — digital ≠ physical.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('No ship '.bin2hex(random_bytes(4)))
            ->setSlug('no-ship-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType(FormType::DIGITAL)
            ->setPriceMinor(2000)->setCurrency('eur');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('input[name="form_definition[allowedShippingCountries][]"]')->count(), 'the country field is always in the DOM');
        self::assertGreaterThan(0, $crawler->filter('.fb-subblock.d-none[data-formbuilder--formtype-target="countries"]')->count(), 'the country block starts hidden on a digital (non-physical) form');
    }

    // --- Faza 5 K7: the "refresh from Dodo" endpoint (GET /admin/forms/dodo-product-info) ---

    public function testDodoProductInfoWithoutIdReturnsError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin/forms/dodo-product-info');

        self::assertResponseIsSuccessful();
        self::assertJson((string) $client->getResponse()->getContent());
        self::assertSame('error', json_decode((string) $client->getResponse()->getContent(), true)['status']);
    }

    public function testDodoProductInfoUnconfiguredReturnsError(): void
    {
        // Dodo is unconfigured in the test env → fetchProductInfo returns null with NO HTTP call → status error.
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin/forms/dodo-product-info', ['id' => 'pdt_whatever']);

        self::assertResponseIsSuccessful();
        self::assertSame('error', json_decode((string) $client->getResponse()->getContent(), true)['status']);
    }

    public function testMorBuilderExposesTheDomContractThePrefillJsNeeds(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // Faza 6 K3.6: the per-row prefill JS fills the FORM fields (single-unit) BY NAME and reads/writes the
        // row targets. Lock that exact DOM contract — a form-prefix / field-name change (or a missing target)
        // would silently kill prefill again. PHPUnit can't run the JS, so we assert the selectors it depends on
        // are actually present in the rendered builder.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Contract '.bin2hex(random_bytes(4)))->setSlug('contract-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_DRAFT)->setFormType(FormType::DIGITAL_MOR)->setMorProvider('fakemor')
            ->setMorUnits([['label' => 'Standard', 'unitId' => 'ok_x', 'priceMinor' => 4900, 'currency' => 'eur']]);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // The FORM fields the JS's formField('…') queries by name (single-unit prefill target).
        foreach (['name', 'description', 'priceMinor', 'currency'] as $field) {
            self::assertStringContainsString('name="form_definition['.$field.']"', $html, "prefill needs form_definition[$field]");
        }
        // The row targets the JS reads/writes.
        foreach (['label', 'unitId', 'priceMinor', 'currency', 'display', 'refreshButton'] as $target) {
            self::assertStringContainsString('data-formbuilder--mor-unit-target="'.$target.'"', $html, "row is missing the $target target");
        }
        // The wrapper carrying the shared refresh URL / provider / labels the row controller reads.
        self::assertStringContainsString('data-mor-units', $html);
        self::assertStringContainsString('data-mor-refresh-url=', $html);
        self::assertStringContainsString('data-mor-provider="fakemor"', $html);

        // K6: the mor-price reveal (hides the form Price/Currency when >1 units) — its controller + targets.
        self::assertStringContainsString('formbuilder--mor-price', $html);
        self::assertStringContainsString('data-formbuilder--mor-price-target="priceBlock"', $html);
        self::assertStringContainsString('data-formbuilder--mor-price-target="priceNote"', $html);
        // No raw i18n key leaks (K3.5/K6 both had a misplaced-key bug).
        self::assertStringNotContainsString('admin.form.builder', $html);
    }

    public function testMorBuilderExposesTheImportUiDomContract(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        // Faza 7 K3: the "import from collection" UI is JS-driven (the button is revealed on connect if the
        // provider has collections). PHPUnit can't run the JS, so lock the DOM contract the controller needs:
        // its controller, the endpoint-URL / provider / labels values, and the button/panel/select/preview/
        // apply targets.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())->setName('Import UI '.bin2hex(random_bytes(4)))->setSlug('import-ui-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_DRAFT)->setFormType(FormType::DIGITAL_MOR)->setMorProvider('fakemor')
            ->setMorUnits([['label' => 'Standard', 'unitId' => 'ok_x', 'priceMinor' => 4900, 'currency' => 'eur']]);
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('formbuilder--mor-import', $html);
        self::assertStringContainsString('data-formbuilder--mor-import-containers-url-value="/admin/forms/mor-containers"', $html);
        self::assertStringContainsString('data-formbuilder--mor-import-units-url-value="/admin/forms/mor-container-units"', $html);
        self::assertStringContainsString('data-formbuilder--mor-import-provider-value="fakemor"', $html);
        foreach (['button', 'panel', 'select', 'preview', 'apply'] as $target) {
            self::assertStringContainsString('data-formbuilder--mor-import-target="'.$target.'"', $html, "import UI is missing the $target target");
        }
        // The reveal button starts hidden (JS reveals it after confirming there are collections).
        self::assertMatchesRegularExpression('/class="[^"]*d-none[^"]*"[^>]*data-formbuilder--mor-import-target="button"/', $html);
        // No raw i18n keys in the import labels/markup.
        self::assertStringNotContainsString('admin.form.mor_import', $html);
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'fb_render_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $this->createdEmails[] = $email;

        return $user;
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        if (null !== $this->formId && null !== ($form = $em->getRepository(FormDefinition::class)->find($this->formId))) {
            $em->remove($form);
            $this->formId = null;
        }

        if (null !== ($setting = $em->getRepository(Setting::class)->findOneBy(['name' => ShippingCatalog::SETTING_KEY]))) {
            $em->remove($setting);
        }

        if ([] !== $this->createdEmails) {
            $repo = $em->getRepository(User::class);
            foreach ($this->createdEmails as $email) {
                if (null !== ($user = $repo->findOneBy(['email' => $email]))) {
                    $em->remove($user);
                }
            }
            $this->createdEmails = [];
        }
        $em->flush();

        parent::tearDown();
    }
}
