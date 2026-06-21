<?php

namespace App\Tests\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use App\Settings\SettingDefinition;
use App\Settings\SettingsEncryptor;
use App\Settings\SettingsManager;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsSection;
use App\Settings\SettingsSectionProviderInterface;
use App\Settings\SettingType;
use PHPUnit\Framework\TestCase;

class SettingsManagerTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $store = [];

    private function manager(): SettingsManager
    {
        $provider = new class implements SettingsSectionProviderInterface {
            public function getSettingsSections(): iterable
            {
                yield new SettingsSection('t', 'T', 'fa', [
                    new SettingDefinition('site_name', SettingType::STRING, 'Name', '', 'Tallyst'),
                    new SettingDefinition('flag', SettingType::BOOL, 'Flag', '', false),
                    new SettingDefinition('count', SettingType::INT, 'Count', '', 7),
                    new SettingDefinition('smtp_password', SettingType::PASSWORD, 'Pass', '', null, [], true),
                ]);
            }
        };
        $registry = new SettingsRegistry([$provider]);
        $encryptor = new SettingsEncryptor(sodium_crypto_secretbox_keygen());

        // In-memory SettingRepository double (constructor bypassed).
        $repo = $this->createStub(SettingRepository::class);
        $repo->method('get')->willReturnCallback(fn (string $name, ?string $default = null) => $this->store[$name] ?? $default);
        $repo->method('set')->willReturnCallback(function (string $name, ?string $value): Setting {
            $this->store[$name] = $value;

            return new Setting($name, $value);
        });

        return new SettingsManager($repo, $registry, $encryptor);
    }

    public function testReturnsTypedDefaultsWhenUnset(): void
    {
        $m = $this->manager();

        self::assertSame('Tallyst', $m->get('site_name'));
        self::assertFalse($m->get('flag'));
        self::assertSame(7, $m->get('count'));
    }

    public function testCastsStoredValuesByType(): void
    {
        $m = $this->manager();
        $m->set('flag', true);
        $m->set('count', '42');

        self::assertTrue($m->get('flag'));
        self::assertSame('1', $this->store['flag'], 'bool stored as 1/0');
        self::assertSame(42, $m->get('count'));
    }

    public function testEncryptedValueIsCiphertextAtRestButReadsBackPlain(): void
    {
        $m = $this->manager();
        $m->set('smtp_password', 's3cret');

        self::assertNotSame('s3cret', $this->store['smtp_password'], 'password stored encrypted');
        self::assertSame('s3cret', $m->get('smtp_password'));
    }

    public function testPasswordIsWriteOnlyEmptyKeepsExisting(): void
    {
        $m = $this->manager();
        $m->set('smtp_password', 's3cret');
        $before = $this->store['smtp_password'];

        $m->set('smtp_password', '');   // empty submit
        self::assertSame($before, $this->store['smtp_password'], 'empty must not overwrite');

        $m->set('smtp_password', null); // also a no-op
        self::assertSame($before, $this->store['smtp_password']);

        self::assertSame('s3cret', $m->get('smtp_password'));
    }

    public function testUndecryptableSecretIsGracefulNotFatal(): void
    {
        $m = $this->manager();
        // A value encrypted with a DIFFERENT (lost/rotated) key — the manager's key can't open it.
        $this->store['smtp_password'] = (new SettingsEncryptor(sodium_crypto_secretbox_keygen()))->encrypt('old');

        self::assertNull($m->get('smtp_password'), 'undecryptable secret reads as default, never throws');
        self::assertFalse($m->isEncryptedValueReadable('smtp_password'));
    }

    public function testReadableAndUnsetSecretsReportReadable(): void
    {
        $m = $this->manager();
        self::assertTrue($m->isEncryptedValueReadable('smtp_password'), 'nothing stored => trivially readable');

        $m->set('smtp_password', 's3cret');
        self::assertTrue($m->isEncryptedValueReadable('smtp_password'));
    }

    public function testGetForFormNeverReturnsSecret(): void
    {
        $m = $this->manager();
        $m->set('smtp_password', 's3cret');

        $registry = new SettingsRegistry([new class implements SettingsSectionProviderInterface {
            public function getSettingsSections(): iterable
            {
                yield new SettingsSection('t', 'T', 'fa', [
                    new SettingDefinition('smtp_password', SettingType::PASSWORD, 'Pass', '', null, [], true),
                ]);
            }
        }]);
        $def = $registry->getDefinition('smtp_password');

        self::assertNull($m->getForForm($def), 'secret never prefilled into the form');
    }
}
