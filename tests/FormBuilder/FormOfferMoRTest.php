<?php

namespace App\Tests\FormBuilder;

use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Shortcode\FormShortcode;

/**
 * The main Phase-3 fix, end-to-end through the [form id=N] render: with BOTH Stripe and Dodo
 * configured, a MoR form (formType DIGITAL_MOR) offers ONLY Dodo on the front — never Stripe — while a
 * pure Stripe form still offers Stripe. Proves FormShortcode reads the resolver.
 * Faza 4 KOMAD 2: the MoR/product decision is now the explicit formType, not the guessed Dodo product.
 */
class FormOfferMoRTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FormShortcode $shortcode;
    /** @var int[] */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->shortcode = $c->get(FormShortcode::class);

        // The render template reads app.request + a csrf token (session) → give it a request with a session.
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $c->get('request_stack')->push($request);

        // Configure BOTH kinds so the resolver has to choose (this is the ambiguous setup the fix targets).
        $settings = $c->get(SettingsManager::class);
        $settings->set('stripe_secret_key', 'sk_test_dummy');
        $settings->set('dodo_api_key', 'dodo_test_dummy');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFormIds as $id) {
            if (null !== ($f = $this->em->find(FormDefinition::class, $id))) {
                $this->em->remove($f);
            }
        }
        $this->em->flush();

        // Reset the provider config we set, so we don't pollute other tests' "unconfigured" expectations.
        $settings = static::getContainer()->get(SettingsManager::class);
        $settings->set('stripe_secret_key', '');
        $settings->set('dodo_api_key', '');

        parent::tearDown();
    }

    private function publishedForm(FormType $type, ?string $dodoProductId, ?array $allowed): FormDefinition
    {
        $f = (new FormDefinition())
            ->setName('T')
            ->setSlug('offer-'.uniqid())
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType($type)
            ->setMorProvider(FormType::DIGITAL_MOR === $type ? 'dodo' : null)
            ->setPriceMinor(4900)
            ->setCurrency('eur')
            ->setDodoProductId($dodoProductId)
            ->setAllowedPaymentMethods($allowed);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdFormIds[] = $f->getId();

        return $f;
    }

    public function testMoRFormOffersOnlyDodoOnFront(): void
    {
        $form = $this->publishedForm(FormType::DIGITAL_MOR, 'prod_123', null); // a MoR form with its Dodo product
        $html = $this->shortcode->render(['id' => (string) $form->getId()]);

        self::assertStringContainsString('name="payment_method" value="dodo"', $html, 'offers Dodo');
        self::assertStringNotContainsString('value="stripe"', $html, 'must NOT offer Stripe on a Dodo form');
    }

    public function testPureStripeFormStillOffersStripe(): void
    {
        $form = $this->publishedForm(FormType::DIGITAL, null, ['stripe']);
        $html = $this->shortcode->render(['id' => (string) $form->getId()]);

        self::assertStringContainsString('name="payment_method" value="stripe"', $html, 'offers Stripe');
        self::assertStringNotContainsString('value="dodo"', $html, 'a Stripe form has no Dodo');
    }
}
