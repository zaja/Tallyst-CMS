<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
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

        $client->request('GET', '/admin/forms/new');

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

        $crawler = $client->request('GET', '/admin/forms/new');
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

        // A MoR form (dodoProductId set) → the whole Delivery-methods field is omitted from the builder.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('MoR ship '.bin2hex(random_bytes(4)))
            ->setSlug('mor-ship-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setPriceMinor(2000)
            ->setCurrency('eur')
            ->setDodoProductId('prod_fake_123');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('input[name="form_definition[shippingMethods][]"]')->count(), 'a MoR form omits the shipping field');
        self::assertSame(0, $crawler->filter('input[name="form_definition[allowedShippingCountries][]"]')->count(), 'a MoR form omits the ship-to-countries field too');
        self::assertStringNotContainsString('Standard delivery — 4,90 EUR', (string) $client->getResponse()->getContent(), 'no shipping choice on a MoR form');
    }

    public function testCountryPickerShownWhenFormOffersDelivery(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $catalog = static::getContainer()->get(ShippingCatalog::class);
        $catalog->save([['key' => 'std12345', 'label' => 'Standard delivery', 'price' => '4.90']]);

        // A form that OFFERS delivery (shippingMethods set → hasShipping) → the country picker appears.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('Ship country '.bin2hex(random_bytes(4)))
            ->setSlug('ship-country-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
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

        // A non-MoR product form WITHOUT shipping methods: the country field is ALWAYS in the DOM (so it
        // can appear LIVE when a method is checked), but its block starts hidden (d-none) — the Stimulus
        // controller reveals it only when a delivery method is checked (JS, not observable via the crawler).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = (new FormDefinition())
            ->setName('No ship '.bin2hex(random_bytes(4)))
            ->setSlug('no-ship-'.bin2hex(random_bytes(4)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setPriceMinor(2000)->setCurrency('eur');
        $em->persist($form);
        $em->flush();
        $this->formId = $form->getId();

        $crawler = $client->request('GET', '/admin/forms/'.$this->formId.'/edit');
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('input[name="form_definition[allowedShippingCountries][]"]')->count(), 'the country field is always in the DOM for a non-MoR form');
        self::assertGreaterThan(0, $crawler->filter('.fb-subblock.d-none[data-formbuilder--formtype-target="countries"]')->count(), 'the country block starts hidden (revealed live by JS when a method is checked)');
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
