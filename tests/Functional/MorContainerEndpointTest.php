<?php

namespace App\Tests\Functional;

use App\Entity\Setting;
use App\Entity\User;
use App\Settings\SettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Faza 7 K2: the read-only "import from collection" endpoints (mor-containers + mor-container-units), driven
 * by the FakeMoRProcessor (provider=fakemor) so there's no HTTP. Covers every edge: configured ok, empty
 * containers, unconfigured → error, a collection with units + skipped, an empty collection, an unknown id,
 * a missing id. ROLE_ADMIN is the controller's class-level guard (shared with the whole builder).
 */
class MorContainerEndpointTest extends WebTestCase
{
    private const CONTAINERS = '/admin/forms/mor-containers?provider=fakemor';
    private const UNITS = '/admin/forms/mor-container-units?provider=fakemor';

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if (null !== ($s = $em->getRepository(Setting::class)->findOneBy(['name' => 'fake_processor_enabled']))) {
            $em->remove($s);
            $em->flush();
        }
        parent::tearDown();
    }

    /**
     * createClient() MUST boot the kernel first, so create it BEFORE touching the container.
     *
     * @return array<string, mixed>
     */
    private function getJson(string $url, bool $enableFake): array
    {
        $client = static::createClient();
        if ($enableFake) {
            static::getContainer()->get(SettingsManager::class)->set('fake_processor_enabled', '1');
        }
        $client->loginUser($this->makeAdmin());
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    public function testContainersConfiguredListsThem(): void
    {
        $data = $this->getJson(self::CONTAINERS, true);

        self::assertSame('ok', $data['status']);
        self::assertSame(['col_full', 'col_empty'], array_column($data['containers'], 'id'));
        self::assertSame('Fake Collection', $data['containers'][0]['name']);
        self::assertSame(3, $data['containers'][0]['productsCount']);
    }

    public function testContainersUnconfiguredIsError(): void
    {
        // fake_processor_enabled is NOT set → FakeMoRProcessor::isConfigured() false → the endpoint must NOT
        // report "no collections" (which would look the same as a genuinely-empty provider) but an error.
        $data = $this->getJson(self::CONTAINERS, false);
        self::assertSame('error', $data['status']);
    }

    public function testContainerUnitsReturnsUnitsAndSkipped(): void
    {
        $data = $this->getJson(self::UNITS.'&id=col_full', true);

        self::assertSame('ok', $data['status']);
        self::assertSame('Fake Collection', $data['name']);
        self::assertSame('A fake collection', $data['description']);
        self::assertSame(['ok_a', 'ok_b'], array_column($data['units'], 'unitId'));
        self::assertSame('Personal', $data['units'][0]['name']);
        self::assertSame('29.00', $data['units'][0]['priceMajor']);
        self::assertSame('eur', $data['units'][0]['currency']);
        // Skipped product with a reason (a subscription).
        self::assertCount(1, $data['skipped']);
        self::assertSame('Monthly', $data['skipped'][0]['name']);
        self::assertSame('recurring', $data['skipped'][0]['reason']);
    }

    public function testContainerUnitsEmptyCollectionIsOkWithNoUnits(): void
    {
        $data = $this->getJson(self::UNITS.'&id=col_empty', true);

        self::assertSame('ok', $data['status']);
        self::assertSame([], $data['units']);
        self::assertSame([], $data['skipped']);
    }

    public function testContainerUnitsUnknownIdIsError(): void
    {
        self::assertSame('error', $this->getJson(self::UNITS.'&id=does_not_exist', true)['status']);
    }

    public function testContainerUnitsMissingIdIsError(): void
    {
        self::assertSame('error', $this->getJson(self::UNITS, true)['status']);
    }

    /** @var string[] */
    private array $emails = [];

    private function makeAdmin(): User
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $email = 'mor_endpoint_'.bin2hex(random_bytes(6)).'@test.local';
        $user = (new User($email))->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $em->persist($user);
        $em->flush();
        $this->emails[] = $email;

        return $user;
    }
}
