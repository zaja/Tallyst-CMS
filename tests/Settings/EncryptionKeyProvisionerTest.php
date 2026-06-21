<?php

namespace App\Tests\Settings;

use App\Settings\EncryptionKeyProvisioner;
use PHPUnit\Framework\TestCase;

class EncryptionKeyProvisionerTest extends TestCase
{
    /** @var string[] */
    private array $dirs = [];

    private function tempProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/tallyst_keytest_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            if (is_file($dir.'/.env.local')) {
                unlink($dir.'/.env.local');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testGeneratesA32ByteKeyWhenMissing(): void
    {
        $dir = $this->tempProjectDir();

        $key = (new EncryptionKeyProvisioner($dir))->ensure();

        self::assertNotNull($key);
        self::assertSame(32, \strlen(base64_decode($key, true)), 'key is 32 raw bytes');
        self::assertStringContainsString('SETTINGS_ENCRYPTION_KEY='.$key, file_get_contents($dir.'/.env.local'));
    }

    public function testDoesNotOverwriteAnExistingKey(): void
    {
        $dir = $this->tempProjectDir();
        file_put_contents($dir.'/.env.local', "SETTINGS_ENCRYPTION_KEY=already-here\n");

        $result = (new EncryptionKeyProvisioner($dir))->ensure();

        self::assertNull($result, 'returns null when a key already exists');
        $contents = file_get_contents($dir.'/.env.local');
        self::assertStringContainsString('already-here', $contents);
        self::assertStringNotContainsString('tallyst/settings', $contents, 'must not append a second key');
    }

    public function testAppendsKeyPreservingExistingEnvLocal(): void
    {
        $dir = $this->tempProjectDir();
        file_put_contents($dir.'/.env.local', "DATABASE_URL=mysql://x\n");

        $key = (new EncryptionKeyProvisioner($dir))->ensure();

        self::assertNotNull($key);
        $contents = file_get_contents($dir.'/.env.local');
        self::assertStringContainsString('DATABASE_URL=mysql://x', $contents, 'existing entries preserved');
        self::assertStringContainsString('SETTINGS_ENCRYPTION_KEY='.$key, $contents);
    }
}
