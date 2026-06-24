<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Proves access is enforced on the ROUTE, not just by hiding the menu: an editor hitting an
 * admin URL directly gets 403. The ADMIN_ONLY list is the COMPLETE set of admin-guarded
 * routes — keep it in sync with the controllers carrying #[IsGranted('ROLE_ADMIN')].
 *
 * Needs the test DB (see verification notes): doctrine:database:create --env=test +
 * doctrine:migrations:migrate --env=test.
 */
class AdminAccessTest extends WebTestCase
{
    /** Reachable by ROLE_EDITOR (content + the editor's media/forms JSON endpoints). */
    private const EDITOR_OK = [
        '/admin', '/admin/page', '/admin/post', '/admin/category',
        '/admin/media', '/admin/media-library', '/admin/forms-list',
    ];

    /** Admin-only — an editor must get 403 on each (complete set of ROLE_ADMIN routes). */
    private const ADMIN_ONLY = [
        '/admin/settings', '/admin/modules', '/admin/user', '/admin/order',
        '/admin/themes', '/admin/menu', '/admin/menu-item', '/admin/setting',
        '/admin/forms', '/admin/email',
    ];

    /** @var string[] */
    private array $createdEmails = [];

    public function testEditorReachesContentButIsForbiddenFromAdminSections(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser(['ROLE_EDITOR']));

        foreach (self::EDITOR_OK as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('editor GET %s', $url));
        }

        foreach (self::ADMIN_ONLY as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, sprintf('editor GET %s must be 403 (route-enforced)', $url));
        }
    }

    public function testAdminReachesEverything(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeUser(['ROLE_ADMIN']));

        foreach ([...self::EDITOR_OK, ...self::ADMIN_ONLY] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful(sprintf('admin GET %s', $url));
        }
    }

    /**
     * @param string[] $roles
     */
    private function makeUser(array $roles): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'access_test_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles($roles);
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
