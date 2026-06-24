<?php

namespace App\Tests\Install;

use App\Install\InstallStateDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstallStateDetectorTest extends TestCase
{
    #[DataProvider('envCases')]
    public function testEnvLocalHasDatabaseUrl(string $contents, bool $expected): void
    {
        self::assertSame($expected, (new InstallStateDetector())->envLocalHasDatabaseUrl($contents));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function envCases(): array
    {
        return [
            'present quoted' => ["DATABASE_URL=\"mysql://u:p@h/db\"\n", true],
            'present bare' => ["DATABASE_URL=mysql://u:p@h/db\n", true],
            'present among others' => ["APP_SECRET=x\nDATABASE_URL=\"mysql://h\"\nDEFAULT_URI=https://a\n", true],
            'empty value' => ["DATABASE_URL=\n", false],
            'absent' => ["APP_SECRET=x\n", false],
            'empty file' => ['', false],
            'commented out' => ["# DATABASE_URL=mysql://h\n", false],
        ];
    }
}
