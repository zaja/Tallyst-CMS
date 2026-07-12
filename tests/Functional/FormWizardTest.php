<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormType;

/**
 * Faza 4 Komad 4: the create-form WIZARD. GET /admin/forms/new shows the questions; POST maps the answers
 * (server-authoritative) to a formType, creates a DRAFT, and redirects into the builder. Every path yields
 * the right type; a physical product never needs Q3 (it can't be MoR).
 */
class FormWizardTest extends WebTestCase
{
    /** @var string[] */
    private array $emails = [];
    /** @var int[] */
    private array $formIds = [];

    public function testWizardRendersTheQuestions(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/forms/new');
        self::assertResponseIsSuccessful();
        // All four question groups' radios are present (Q2/Q3/Q4 hidden by JS, still in the DOM).
        self::assertGreaterThan(0, $crawler->filter('input[name="q1"][value="messages"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q1"][value="sells"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q2"][value="physical"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q3"][value="mor"]')->count());
        // Q4 renders one card per registered MoR provider (Dodo is registered).
        self::assertGreaterThan(0, $crawler->filter('input[name="q4"][value="dodo"]')->count(), 'the Dodo provider card renders');
        // No MoR provider is configured in the test env → the Q3 "provider" option is disabled.
        self::assertGreaterThan(0, $crawler->filter('input[name="q3"][value="mor"][disabled]')->count(), 'Q3 provider disabled with no configured MoR');
        self::assertGreaterThan(0, $crawler->filter('form[action$="/admin/forms/new"]')->count());
    }

    public function testMessagesPathCreatesAFreeDraft(): void
    {
        $form = $this->wizard(['q1' => 'messages']);
        self::assertSame(FormType::MESSAGES, $form->getFormType());
        self::assertNull($form->getMorProvider(), 'a free form has no MoR provider');
    }

    public function testPhysicalPathSkipsQ3(): void
    {
        // Even if a forged q3 arrives, physical wins (it can't be MoR).
        $form = $this->wizard(['q1' => 'sells', 'q2' => 'physical', 'q3' => 'mor']);
        self::assertSame(FormType::PHYSICAL, $form->getFormType());
        self::assertNull($form->getMorProvider());
    }

    public function testDigitalSelfPath(): void
    {
        $form = $this->wizard(['q1' => 'sells', 'q2' => 'digital', 'q3' => 'self']);
        self::assertSame(FormType::DIGITAL, $form->getFormType());
        self::assertNull($form->getMorProvider());
    }

    public function testDigitalMoRPathRecordsTheChosenProvider(): void
    {
        // Faza 5 K3: Q4 picks WHICH provider; the draft records it.
        $form = $this->wizard(['q1' => 'sells', 'q2' => 'digital', 'q3' => 'mor', 'q4' => 'dodo']);
        self::assertSame(FormType::DIGITAL_MOR, $form->getFormType());
        self::assertSame('dodo', $form->getMorProvider());
    }

    public function testMissingOrForgedProviderFallsBackToARegisteredMoR(): void
    {
        // No Q4 (or an invalid one) → the draft still gets a VALID registered MoR provider (never invalid),
        // so the MorProviderMatchesType invariant holds even on a forged POST.
        $form = $this->wizard(['q1' => 'sells', 'q2' => 'digital', 'q3' => 'mor', 'q4' => 'not_a_provider']);
        self::assertSame(FormType::DIGITAL_MOR, $form->getFormType());
        self::assertContains($form->getMorProvider(), ['dodo', 'fakemor'], 'falls back to a registered MoR provider');
    }

    public function testIncompleteFallsBackToMessages(): void
    {
        // A "sells" with no Q2 is not a valid path → a harmless free draft (never a 500).
        self::assertSame(FormType::MESSAGES, $this->wizard(['q1' => 'sells'])->getFormType());
    }

    /** Run the wizard POST with the given answers → returns the created draft (redirect followed). */
    private function wizard(array $answers): FormDefinition
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/forms/new');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/forms/new', array_merge(['_token' => $token], $answers));
        self::assertResponseRedirects();

        // The redirect lands on the builder for the new draft: /admin/forms/{id}/edit.
        $location = $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/admin/forms/\d+/edit$#', (string) $location);
        preg_match('#/admin/forms/(\d+)/edit#', (string) $location, $m);
        $id = (int) $m[1];
        $this->formIds[] = $id;

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $form = $em->getRepository(FormDefinition::class)->find($id);
        self::assertNotNull($form, 'the wizard created a draft');
        self::assertSame(FormDefinition::STATUS_DRAFT, $form->getStatus(), 'created as a draft');

        return $form;
    }

    public function testProviderOptionsEnabledWhenConfigured(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        // Configure Dodo → the Q3 "provider" option and the Dodo Q4 card become selectable.
        static::getContainer()->get(\App\Settings\SettingsManager::class)->set('dodo_api_key', 'dodo_test_key');

        $crawler = $client->request('GET', '/admin/forms/new');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('input[name="q3"][value="mor"][disabled]')->count(), 'Q3 provider enabled when a MoR is configured');
        self::assertSame(0, $crawler->filter('input[name="q4"][value="dodo"][disabled]')->count(), 'the Dodo card is enabled');
    }

    private function makeAdmin(): User
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $email = 'wiz_'.bin2hex(random_bytes(6)).'@test.local';
        $u = (new User($email))->setRoles(['ROLE_ADMIN']);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password123'));
        $em->persist($u);
        $em->flush();
        $this->emails[] = $email;

        return $u;
    }

    protected function tearDown(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach ($this->formIds as $id) {
            $conn->executeStatement('DELETE FROM fb_form WHERE id = ?', [$id]);
        }
        foreach ($this->emails as $email) {
            $conn->executeStatement('DELETE FROM user WHERE email = ?', [$email]);
        }
        // dodo_api_key may have been set (testProviderOptionsEnabledWhenConfigured) — remove it directly
        // (an encrypted write-only setting can't be cleared via SettingsManager's empty-is-a-no-op guard).
        $conn->executeStatement("DELETE FROM setting WHERE name = 'dodo_api_key'");
        $this->formIds = $this->emails = [];

        parent::tearDown();
    }
}
