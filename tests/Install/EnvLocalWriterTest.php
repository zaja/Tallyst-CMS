<?php

namespace App\Tests\Install;

use App\Install\EnvLocalWriter;
use PHPUnit\Framework\TestCase;

class EnvLocalWriterTest extends TestCase
{
    /** @var string[] */
    private array $dirs = [];

    private function tempProjectDir(): string
    {
        $dir = sys_get_temp_dir().'/tallyst_envtest_'.bin2hex(random_bytes(6));
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

    public function testAppendsKeysWhenFileIsMissing(): void
    {
        $dir = $this->tempProjectDir();
        $writer = new EnvLocalWriter($dir);

        $writer->upsert(['APP_SECRET' => 'abc123', 'DEFAULT_URI' => 'https://tallyst.org']);

        $contents = file_get_contents($dir.'/.env.local');
        self::assertStringContainsString('APP_SECRET="abc123"', $contents);
        self::assertStringContainsString('DEFAULT_URI="https://tallyst.org"', $contents);
    }

    public function testReplacesAnExistingKeyInPlaceAndPreservesOtherLines(): void
    {
        $dir = $this->tempProjectDir();
        file_put_contents($dir.'/.env.local', "# comment\nDATABASE_URL=\"old\"\n###> tallyst/settings ###\nSETTINGS_ENCRYPTION_KEY=keepme\n###< tallyst/settings ###\n");

        (new EnvLocalWriter($dir))->upsert(['DATABASE_URL' => 'mysql://u:p@h:3306/db?serverVersion=8.4.0&charset=utf8mb4']);

        $contents = file_get_contents($dir.'/.env.local');
        self::assertStringContainsString('DATABASE_URL="mysql://u:p@h:3306/db?serverVersion=8.4.0&charset=utf8mb4"', $contents);
        self::assertStringNotContainsString('"old"', $contents);
        // Untouched lines survive.
        self::assertStringContainsString('# comment', $contents);
        self::assertStringContainsString('SETTINGS_ENCRYPTION_KEY=keepme', $contents);
        // No duplicate key.
        self::assertSame(1, substr_count($contents, 'DATABASE_URL='));
    }

    public function testEscapesDollarAndQuotesAndBackslash(): void
    {
        $dir = $this->tempProjectDir();

        (new EnvLocalWriter($dir))->upsert(['ORDER_ADMIN_EMAIL' => 'a$b"c\\d']);

        $contents = file_get_contents($dir.'/.env.local');
        self::assertStringContainsString('ORDER_ADMIN_EMAIL="a\\$b\\"c\\\\d"', $contents);
    }

    public function testHasNonEmptyDetectsValues(): void
    {
        $dir = $this->tempProjectDir();
        file_put_contents($dir.'/.env.local', "APP_SECRET=\nDATABASE_URL=\"mysql://x\"\n");
        $writer = new EnvLocalWriter($dir);

        self::assertFalse($writer->hasNonEmpty('APP_SECRET'), 'empty value is not "non-empty"');
        self::assertTrue($writer->hasNonEmpty('DATABASE_URL'));
        self::assertFalse($writer->hasNonEmpty('MISSING'));
    }

    public function testIsIdempotentAcrossRuns(): void
    {
        $dir = $this->tempProjectDir();
        $writer = new EnvLocalWriter($dir);

        $writer->upsert(['DEFAULT_URI' => 'https://a.test']);
        $writer->upsert(['DEFAULT_URI' => 'https://a.test']);

        $contents = file_get_contents($dir.'/.env.local');
        self::assertSame(1, substr_count($contents, 'DEFAULT_URI='));
    }

    public function testLocksFilePermsTo0600(): void
    {
        $dir = $this->tempProjectDir();
        (new EnvLocalWriter($dir))->upsert(['APP_SECRET' => 'x']);

        self::assertSame('0600', substr(sprintf('%o', fileperms($dir.'/.env.local')), -4));
    }
}
