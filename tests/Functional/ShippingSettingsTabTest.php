<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tallyst\FormBuilder\Service\ShippingCatalog;

/**
 * Komad 1: the "Shipping" (Dostava) settings tab + JSON catalog. The tab is a standalone tab with NO
 * scalar settings (an ungrouped section → its own tab); its list is edited by a custom collection
 * editor that POSTs to a FormBuilder route which saves one JSON setting. Needs the migrated test DB.
 */
class ShippingSettingsTabTest extends WebTestCase
{
    /** @var string[] */
    private array $createdEmails = [];

    public function testShippingTabRenders(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/shipping');
        self::assertResponseIsSuccessful();

        // The catalog editor (add button) + the separate save form the rows bind to.
        self::assertGreaterThan(0, $crawler->filter('[data-action="formbuilder--builder#add"]')->count(), 'the "add method" button renders');
        self::assertGreaterThan(0, $crawler->filter('form#settings-shipping')->count(), 'the separate shipping save form renders');
        // The catalog Save binds to that separate form (form= attribute), and the generic tab Save
        // (a .btn-primary submit with NO form=) is suppressed on this scalar-less tab.
        self::assertGreaterThan(0, $crawler->filter('button[type="submit"][form="settings-shipping"]')->count(), 'the catalog Save renders');
        self::assertSame(0, $crawler->filter('button.btn-primary[type="submit"]:not([form])')->count(), 'the generic tab Save is suppressed');
    }

    public function testSaveRoundTripsThroughTheCatalog(): void
    {
        $client = static::createClient();
        $client->loginUser($this->makeAdmin());

        $crawler = $client->request('GET', '/admin/settings/shipping');
        $token = $crawler->filter('form#settings-shipping input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/settings/shipping/save', [
            '_token' => $token,
            'methods' => [
                ['key' => '', 'label' => 'Standard delivery', 'price' => '4.90'],
                ['key' => '', 'label' => 'Express', 'price' => '12'],
                ['key' => '', 'label' => '', 'price' => ''], // blank row → dropped
            ],
        ]);
        self::assertResponseRedirects('/admin/settings/shipping');

        $catalog = static::getContainer()->get(ShippingCatalog::class);
        $all = $catalog->all();

        self::assertCount(2, $all, 'the blank row was dropped');
        self::assertSame('Standard delivery', $all[0]['label']);
        self::assertSame(490, $all[0]['priceMinor']);
        self::assertSame('Express', $all[1]['label']);
        self::assertSame(1200, $all[1]['priceMinor']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $all[0]['key'], 'a stable random key was generated');
    }

    private function makeAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'shipping_tab_'.bin2hex(random_bytes(6)).'@test.local';
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

        if (null !== ($setting = $em->getRepository(Setting::class)->findOneBy(['name' => ShippingCatalog::SETTING_KEY]))) {
            $em->remove($setting);
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
