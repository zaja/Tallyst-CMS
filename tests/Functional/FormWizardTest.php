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
        // The three question groups' radios are present (Q2/Q3 hidden by JS, still in the DOM).
        self::assertGreaterThan(0, $crawler->filter('input[name="q1"][value="messages"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q1"][value="sells"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q2"][value="physical"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="q3"][value="mor"]')->count());
        self::assertGreaterThan(0, $crawler->filter('form[action$="/admin/forms/new"]')->count());
    }

    public function testMessagesPathCreatesAFreeDraft(): void
    {
        self::assertSame(FormType::MESSAGES, $this->wizard(['q1' => 'messages']));
    }

    public function testPhysicalPathSkipsQ3(): void
    {
        // Even if a forged q3 arrives, physical wins (it can't be MoR).
        self::assertSame(FormType::PHYSICAL, $this->wizard(['q1' => 'sells', 'q2' => 'physical', 'q3' => 'mor']));
    }

    public function testDigitalSelfPath(): void
    {
        self::assertSame(FormType::DIGITAL, $this->wizard(['q1' => 'sells', 'q2' => 'digital', 'q3' => 'self']));
    }

    public function testDigitalMoRPath(): void
    {
        self::assertSame(FormType::DIGITAL_MOR, $this->wizard(['q1' => 'sells', 'q2' => 'digital', 'q3' => 'mor']));
    }

    public function testIncompleteFallsBackToMessages(): void
    {
        // A "sells" with no Q2 is not a valid path → a harmless free draft (never a 500).
        self::assertSame(FormType::MESSAGES, $this->wizard(['q1' => 'sells']));
    }

    /** Run the wizard POST with the given answers → returns the created draft's FormType (redirect followed). */
    private function wizard(array $answers): FormType
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

        return $form->getFormType();
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
        $this->formIds = $this->emails = [];

        parent::tearDown();
    }
}
