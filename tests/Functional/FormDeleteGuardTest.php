<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Entity\FormDefinition;
use Tallyst\FormBuilder\Entity\FormSubmission;
use Tallyst\FormBuilder\Entity\FormType;
use Tallyst\FormBuilder\Entity\Order;

/**
 * Proves the protection lives on the ROUTE, not in the template: a form that carries orders cannot
 * be deleted even by a hand-crafted POST with a VALID CSRF token (the list no longer renders that
 * button at all). When this was written, `fb_order.form_id ON DELETE CASCADE` meant one click would
 * wipe the order history; migration Version20260807081500 made the orders survive on their own, so
 * the rule now guards the link between a sale and its form rather than the sale itself.
 *
 * Needs the test DB (see AdminAccessTest): doctrine:database:create --env=test +
 * doctrine:migrations:migrate --env=test.
 */
class FormDeleteGuardTest extends WebTestCase
{
    /** @var int[] */
    private array $formIds = [];
    /** @var string[] */
    private array $createdEmails = [];
    private ?string $previousLocale = null;
    private ?bool $localeRowExisted = null;

    /**
     * The core guarantee. The token is taken from the list BEFORE the order exists (so it is a
     * genuinely valid token for this session), the order is added, and only then is the delete
     * posted — otherwise a rejection could just mean "bad CSRF" and would prove nothing.
     */
    public function testFormWithAnOrderCannotBeDeletedThroughTheRoute(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Pin the locale: this test asserts on DISPLAYED text, and app_locale is a global setting
        // any other test may have left flipped. tearDown restores whatever was there.
        $this->withLocale($em, 'en');

        $form = $this->makeForm($em, 'Guard order form', FormType::DIGITAL);
        $token = $this->deleteTokenFromList($client, $form->getId());
        self::assertNotNull($token, 'a form without orders must still render its delete button + token');

        $order = $this->makeOrder($em, $form);
        $orderId = $order->getId();

        $client->request('POST', '/admin/forms/'.$form->getId().'/delete', ['_token' => $token]);
        $client->followRedirect();

        $em->clear();
        self::assertNotNull(
            $em->getRepository(FormDefinition::class)->find($this->formIds[0]),
            'the form must survive a valid-CSRF delete POST'
        );
        self::assertNotNull(
            $em->getRepository(Order::class)->find($orderId),
            'the order must survive, still attached to its form — the delete never went through'
        );
        self::assertStringContainsString('cannot be deleted', $client->getResponse()->getContent());
    }

    /** Messages alone never block — but the form and its messages do go, as the confirmation warns. */
    public function testFormWithMessagesButNoOrdersIsDeleted(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Guard message form', FormType::MESSAGES);
        $formId = $form->getId();
        $submissionId = $this->makeSubmission($em, $form)->getId();

        $token = $this->deleteTokenFromList($client, $formId);
        self::assertNotNull($token);

        $client->request('POST', '/admin/forms/'.$formId.'/delete', ['_token' => $token]);

        $em->clear();
        self::assertNull($em->getRepository(FormDefinition::class)->find($formId), 'the form must be deleted');
        self::assertNull(
            $em->getRepository(FormSubmission::class)->find($submissionId),
            'its messages go with it (CASCADE) — exactly what the confirmation warns about'
        );
    }

    public function testEmptyFormIsDeleted(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Guard empty form', FormType::MESSAGES);
        $formId = $form->getId();

        $token = $this->deleteTokenFromList($client, $formId);
        self::assertNotNull($token);

        $client->request('POST', '/admin/forms/'.$formId.'/delete', ['_token' => $token]);

        $em->clear();
        self::assertNull($em->getRepository(FormDefinition::class)->find($formId));
    }

    /**
     * The list must show the real per-form order count (it is what explains the disabled button on
     * a touch screen, where no tooltip exists), AND the blocked row must render no delete form.
     */
    public function testListShowsTheOrderCountAndDropsTheDeleteButton(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $form = $this->makeForm($em, 'Guard count form', FormType::DIGITAL);
        for ($i = 0; $i < 4; ++$i) {
            $this->makeOrder($em, $form);
        }

        $crawler = $client->request('GET', '/admin/forms');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('tr')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Guard count form')
        )->first();
        self::assertSame('4', trim($row->filter('td')->eq(5)->text()), 'the orders column must show the real count');
        self::assertCount(0, $row->filter('form'), 'a blocked row must not render a delete form/CSRF token at all');
        self::assertCount(1, $row->filter('button[disabled]'), 'the delete button must be rendered disabled');
    }

    /**
     * Locks the ICU pluralization end-to-end: the rendered text must carry the NUMBER in the right
     * Croatian form, never the raw `{count, plural, ...}` source. 4 → "narudžbe" (few), 5 → "narudžbi"
     * (other) — the two forms a naive "number + fixed word" implementation would get wrong.
     */
    public function testCroatianPluralIsRenderedNotLeftAsIcuSource(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->withLocale($em, 'hr');

        $few = $this->makeForm($em, 'Guard plural few', FormType::DIGITAL);
        for ($i = 0; $i < 4; ++$i) {
            $this->makeOrder($em, $few);
        }
        $many = $this->makeForm($em, 'Guard plural many', FormType::DIGITAL);
        for ($i = 0; $i < 5; ++$i) {
            $this->makeOrder($em, $many);
        }

        $html = $client->request('GET', '/admin/forms')->html();

        self::assertStringNotContainsString('{count, plural', $html, 'the ICU source must never reach the page');
        self::assertStringContainsString('4 narudžbe', $html, '4 must take the FEW form');
        self::assertStringContainsString('5 narudžbi', $html, '5 must take the OTHER form');
    }

    // --- fixtures -------------------------------------------------------------------------------

    private function makeForm(EntityManagerInterface $em, string $name, FormType $type): FormDefinition
    {
        $form = (new FormDefinition())
            ->setName($name)
            ->setSlug('guard-'.bin2hex(random_bytes(5)))
            ->setStatus(FormDefinition::STATUS_PUBLISHED)
            ->setFormType($type);

        if (FormType::MESSAGES !== $type) {
            $form->setPriceMinor(1000)->setCurrency('eur');
        }

        $em->persist($form);
        $em->flush();
        $this->formIds[] = $form->getId();

        return $form;
    }

    /** A `pending` order — ANY status must block, not just paid ones. */
    private function makeOrder(EntityManagerInterface $em, FormDefinition $form): Order
    {
        $order = (new Order())
            ->setForm($form)
            ->setAmountMinor(1000)
            ->setCurrency('eur');

        $em->persist($order);
        $em->flush();

        return $order;
    }

    private function makeSubmission(EntityManagerInterface $em, FormDefinition $form): FormSubmission
    {
        $submission = (new FormSubmission())->setForm($form)->setData(['name' => 'Guard test']);
        $em->persist($submission);
        $em->flush();

        return $submission;
    }

    /** The real token the list renders for this form, or null when the row has no delete form. */
    private function deleteTokenFromList(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $formId): ?string
    {
        $crawler = $client->request('GET', '/admin/forms');
        self::assertResponseIsSuccessful();

        $input = $crawler->filter('form[action$="/admin/forms/'.$formId.'/delete"] input[name="_token"]');

        return $input->count() > 0 ? $input->attr('value') : null;
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'form_delete_guard_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $em->persist($user);
        $em->flush();
        $this->createdEmails[] = $email;

        return $user;
    }

    /**
     * Set the global app_locale (LocaleSubscriber reads it). This is a PERSISTED setting shared by
     * the whole test DB, so tearDown restores the exact prior state — including the common case
     * where NO row existed at all (the schema default applied), which must be left row-less rather
     * than "restored" to a value that was never stored.
     */
    private function withLocale(EntityManagerInterface $em, string $locale): void
    {
        $stored = static::getContainer()->get(\App\Repository\SettingRepository::class)->get('app_locale');
        $this->localeRowExisted ??= null !== $stored;
        $this->previousLocale ??= $stored;

        static::getContainer()->get(\App\Settings\SettingsManager::class)->set('app_locale', $locale);
        $em->flush();
    }

    /** Put app_locale back exactly as it was: same value, or no row at all. */
    private function restoreLocale(EntityManagerInterface $em): void
    {
        if (null === $this->localeRowExisted) {
            return; // never touched
        }

        if ($this->localeRowExisted) {
            static::getContainer()->get(\App\Settings\SettingsManager::class)->set('app_locale', (string) $this->previousLocale);
        } else {
            $repo = $em->getRepository(\App\Entity\Setting::class);
            if (null !== ($row = $repo->findOneBy(['name' => 'app_locale']))) {
                $em->remove($row);
            }
        }

        $this->localeRowExisted = null;
        $this->previousLocale = null;
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        // Child-first, and the ORDERS must go explicitly: deleting the form only CASCADEs its messages
        // now, so orders would otherwise be left behind with form_id set to NULL (SET NULL).
        foreach ($this->formIds as $id) {
            $form = $em->getRepository(FormDefinition::class)->find($id);
            if (null === $form) {
                continue; // already deleted by the test itself
            }
            foreach ($em->getRepository(Order::class)->findBy(['form' => $form]) as $order) {
                $em->remove($order);
            }
            foreach ($em->getRepository(FormSubmission::class)->findBy(['form' => $form]) as $submission) {
                $em->remove($submission);
            }
            $em->remove($form);
        }

        foreach ($this->createdEmails as $email) {
            if (null !== ($user = $em->getRepository(User::class)->findOneBy(['email' => $email]))) {
                $em->remove($user);
            }
        }

        $this->restoreLocale($em);

        $em->flush();
        $this->formIds = [];
        $this->createdEmails = [];

        parent::tearDown();
    }
}
