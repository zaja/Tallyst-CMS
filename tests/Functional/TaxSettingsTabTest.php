<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Service\TaxCatalog;

/**
 * Faza 3 Komad 2/2.5: the "Porez" tax tab has ONE Save that persists BOTH the tax_enabled master toggle
 * (now bound to the FormBuilder settings-tax form, not the generic scalar schema) AND the named-rate
 * catalog. Backward-compat: with no `tax_rates` saved yet, the editor lazily shows the default rate
 * synthesized from the legacy scalars (PDV/25). Needs the migrated test DB.
 */
class TaxSettingsTabTest extends WebTestCase
{
    /** Tax settings this test writes/reads — cleared so the lazy PDV/25 default is deterministic. */
    private const TAX_SETTINGS = [TaxCatalog::SETTING_KEY, 'tax_rate', 'tax_name', 'tax_enabled'];

    /** @var string[] */
    private array $createdEmails = [];

    private function clearTaxSettings(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach (self::TAX_SETTINGS as $name) {
            if (null !== ($s = $em->getRepository(Setting::class)->findOneBy(['name' => $name]))) {
                $em->remove($s);
            }
        }
        $em->flush();
    }

    public function testTaxTabRendersToggleAndLazyDefaultRate(): void
    {
        $client = static::createClient();
        $this->clearTaxSettings();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/tax');
        self::assertResponseIsSuccessful();

        // The master toggle + the catalog editor + ONE save form. tax_enabled now binds to the settings-tax
        // form (not the generic form[...] schema) so a single Save persists both.
        self::assertGreaterThan(0, $crawler->filter('input[name="tax_enabled"][form="settings-tax"]')->count(), 'the tax_enabled master toggle binds to the tax form');
        self::assertSame(0, $crawler->filter('[name="form[tax_enabled]"]')->count(), 'tax_enabled is no longer a generic scalar field');
        self::assertGreaterThan(0, $crawler->filter('[data-action="formbuilder--builder#add"]')->count(), 'the "add rate" button renders');
        self::assertGreaterThan(0, $crawler->filter('form#settings-tax')->count());
        // Exactly one Save button on the tax tab (the generic Save is auto-hidden — the section has no scalars).
        self::assertSame(1, $crawler->filter('button[type="submit"][form="settings-tax"]')->count(), 'one Save button');

        // Lazy default: with no tax_rates saved, the editor shows the synthesized PDV/25 default row.
        self::assertGreaterThan(0, $crawler->filter('input[name="rates[0][name]"][value="PDV"]')->count(), 'lazy default name');
        self::assertGreaterThan(0, $crawler->filter('input[name="rates[0][rate]"][value="25"]')->count(), 'lazy default rate');
        self::assertGreaterThan(0, $crawler->filter('input[name="default_row"][value="0"]')->count(), 'the default radio is present on the default row');
    }

    public function testSaveRoundTripsThroughTheCatalog(): void
    {
        $client = static::createClient();
        $this->clearTaxSettings();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/tax');
        $token = $crawler->filter('form#settings-tax input[name="_token"]')->attr('value');

        // ONE submit carries BOTH the master toggle and the rate list.
        $client->request('POST', '/admin/settings/tax/save', [
            '_token' => $token,
            'tax_enabled' => '1', // master switch, in the same form
            'rates' => [
                ['key' => '', 'name' => 'PDV', 'rate' => '25'],
                ['key' => '', 'name' => 'Reduced', 'rate' => '13,5'], // comma decimal
                ['key' => '', 'name' => '', 'rate' => ''],            // blank row → dropped
            ],
            'default_row' => '1', // mark the second (Reduced) as default
        ]);
        self::assertResponseRedirects('/admin/settings/tax');

        // tax_enabled persisted through the same save.
        self::assertTrue((bool) static::getContainer()->get(\App\Settings\SettingsManager::class)->get('tax_enabled'), 'the master toggle saved together with the rates');

        $all = static::getContainer()->get(TaxCatalog::class)->all();
        self::assertCount(2, $all, 'the blank row was dropped');
        self::assertSame('PDV', $all[0]['name']);
        self::assertSame('25', $all[0]['rate']);
        self::assertFalse($all[0]['default']);
        self::assertSame('Reduced', $all[1]['name']);
        self::assertSame('13.5', $all[1]['rate'], 'comma decimal normalized');
        self::assertTrue($all[1]['default'], 'the chosen default_row is the default');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $all[0]['key']);
    }

    public function testUncheckedTogglePersistsAsDisabled(): void
    {
        $client = static::createClient();
        $this->clearTaxSettings();
        $client->loginUser($this->makeAdmin());
        // Seed enabled so we can prove the unchecked submit turns it OFF.
        static::getContainer()->get(\App\Settings\SettingsManager::class)->set('tax_enabled', '1');

        $crawler = $client->request('GET', '/admin/settings/tax');
        $token = $crawler->filter('form#settings-tax input[name="_token"]')->attr('value');

        // No `tax_enabled` key → an unchecked checkbox → the master switch turns off.
        $client->request('POST', '/admin/settings/tax/save', [
            '_token' => $token,
            'rates' => [['key' => '', 'name' => 'PDV', 'rate' => '25']],
            'default_row' => '0',
        ]);
        self::assertResponseRedirects('/admin/settings/tax');
        self::assertFalse((bool) static::getContainer()->get(\App\Settings\SettingsManager::class)->get('tax_enabled'), 'unchecked → disabled');
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'tax_tab_'.bin2hex(random_bytes(6)).'@test.local';
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
        foreach (self::TAX_SETTINGS as $name) {
            if (null !== ($s = $em->getRepository(Setting::class)->findOneBy(['name' => $name]))) {
                $em->remove($s);
            }
        }
        $userRepo = $em->getRepository(User::class);
        foreach ($this->createdEmails as $email) {
            if (null !== ($user = $userRepo->findOneBy(['email' => $email]))) {
                $em->remove($user);
            }
        }
        $em->flush();
        $this->createdEmails = [];

        parent::tearDown();
    }
}
