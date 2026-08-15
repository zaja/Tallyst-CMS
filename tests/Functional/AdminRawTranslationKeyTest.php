<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * No admin screen may print a translation KEY where a human expects words.
 *
 * ⚠ AN UNRESOLVED KEY FAILS COMPLETELY SILENTLY. Twig and Symfony's form layer print it verbatim:
 * nothing throws, nothing is logged, every status code stays 200, and the page is simply broken for
 * whoever reads it. It happened three times in two days — a status filter, a flood warning, and a
 * readiness row — each found by a human looking at the screen.
 *
 * ⚠ THE ROOT CAUSE WAS NOT ANY OF THOSE THREE BUGS. It was that this test used to enumerate screens
 * by hand: it listed the screens its author had just been looking at, not the screens that exist.
 * Every widening after a new escape would have been the same mistake again. So the inventory is now
 * READ, from the two places that already maintain one:
 *
 *  - AdminAccessTest::EDITOR_OK + ::ADMIN_ONLY — the complete set of admin routes, kept current
 *    because the access test fails when it is not;
 *  - the CRUD controller classes on disk — for filter panels, which are not URLs.
 *
 * Adding a screen therefore adds it here, without anybody remembering to.
 */
class AdminRawTranslationKeyTest extends WebTestCase
{
    /** @var string[] */
    private array $createdEmails = [];

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->createdEmails as $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $user) {
                $em->remove($user);
            }
        }
        $em->flush();
        $this->createdEmails = [];
        parent::tearDown();
    }

    private function admin(KernelBrowser $client): User
    {
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'rawkey-'.bin2hex(random_bytes(5)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'Str0ng-Passw0rd-Here'));
        $em->persist($user);
        $em->flush();
        $this->createdEmails[] = $email;

        return $user;
    }

    /** Every `admin.something.something` left unresolved in the given HTML. */
    private function rawKeys(string $html): array
    {
        preg_match_all('/\badmin\.[a-z0-9_]+\.[a-z0-9_.]+/', $html, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** Every CRUD controller that exists, found on disk rather than remembered. */
    private function crudControllers(): array
    {
        $found = [];
        foreach (['src/Controller/Admin', 'modules/*/Controller/Admin'] as $pattern) {
            foreach (glob(\dirname(__DIR__, 2).'/'.$pattern.'/*CrudController.php') ?: [] as $file) {
                $source = (string) file_get_contents($file);
                if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                    && preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $cls)) {
                    $found[] = trim($ns[1]).'\\'.$cls[1];
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * ⚠ FILTER PANELS ARE RENDERED BY THEIR OWN ACTION, so fetching the list page never reaches
     * them — which is how a whole dropdown of raw keys sat unnoticed beside a list that looked
     * perfect. EasyAdmin pins filter forms to its own catalogue, so an `admin.*` key handed to
     * `ChoiceFilter::new()` is printed verbatim; `translatedChoiceFilter()` in AdminCrudPolishTrait
     * is the fix.
     */
    public function testNoFilterPanelShowsRawTranslationKeys(): void
    {
        $client = static::createClient();
        $client->followRedirects(true);
        $client->loginUser($this->admin($client));

        $controllers = $this->crudControllers();
        self::assertNotEmpty($controllers, 'the controller inventory must not come back empty');

        foreach ($controllers as $fqcn) {
            $client->request('GET', '/admin?crudControllerFqcn='.urlencode($fqcn).'&crudAction=renderFilters');

            if (!$client->getResponse()->isSuccessful()) {
                continue; // a controller without filters is not a failure
            }

            $raw = $this->rawKeys((string) $client->getResponse()->getContent());

            self::assertSame(
                [],
                $raw,
                \sprintf("%s shows raw translation keys in its filters: %s\n".
                    'Use translatedChoiceFilter() from AdminCrudPolishTrait — EasyAdmin pins filter '.
                    'forms to its own catalogue, so an admin.* key is printed verbatim.',
                    $fqcn, implode(', ', $raw)),
            );
        }
    }

    /**
     * Every admin screen there is, read from the access test's inventory.
     *
     * ⚠ THE READINESS PANEL IS THE ONE TO KEEP IN MIND HERE. Its rows are built in PHP by tagged
     * providers rather than rendered from a CRUD field list, so a MODULE can contribute a row whose
     * keys are nested under the wrong parent and nothing anywhere objects — the panel just prints
     * `admin.readiness.order_sweep.label` at the owner, on the screen where they decide whether
     * their shop is in trouble. That is exactly what happened on 2026-08-15.
     */
    public function testNoAdminScreenShowsRawTranslationKeys(): void
    {
        $client = static::createClient();
        $client->followRedirects(true);
        $client->loginUser($this->admin($client));

        $screens = array_unique(array_merge(AdminAccessTest::EDITOR_OK, AdminAccessTest::ADMIN_ONLY));
        self::assertGreaterThan(15, \count($screens), 'the screen inventory looks suspiciously short');

        foreach ($screens as $url) {
            $client->request('GET', $url);

            // JSON endpoints for the editor live in the same inventory; they carry no UI text.
            $type = (string) $client->getResponse()->headers->get('Content-Type');
            if (!$client->getResponse()->isSuccessful() || !str_contains($type, 'html')) {
                continue;
            }

            $raw = $this->rawKeys((string) $client->getResponse()->getContent());

            self::assertSame([], $raw, $url.' shows raw translation keys: '.implode(', ', $raw));
        }
    }
}
