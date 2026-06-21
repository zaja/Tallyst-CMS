<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Renders the form builder (new form) end-to-end. This exercises the field-row PROTOTYPE
 * (form.fields.vars.prototype), whose `vars.value` is null — so it guards the collapsible-row
 * template's null-safe summary against a dev strict_variables 500.
 */
class FormBuilderBuilderRenderTest extends WebTestCase
{
    /** @var string[] */
    private array $createdEmails = [];

    public function testBuilderPageRendersWithCollapsibleFieldPrototype(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $client->request('GET', '/admin/forms/new');

        self::assertResponseIsSuccessful();
        // The collapsed-row summary markup is present (carried in the field prototype).
        self::assertStringContainsString('fb-row-summary', (string) $client->getResponse()->getContent());
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
        if ([] !== $this->createdEmails) {
            $em = static::getContainer()->get(EntityManagerInterface::class);
            $repo = $em->getRepository(User::class);
            foreach ($this->createdEmails as $email) {
                if (null !== ($user = $repo->findOneBy(['email' => $email]))) {
                    $em->remove($user);
                }
            }
            $em->flush();
            $this->createdEmails = [];
        }

        parent::tearDown();
    }
}
