<?php

namespace App\Tests\Settings;

use App\Settings\SettingsManager;
use App\Twig\SettingsExtension;
use PHPUnit\Framework\TestCase;

class SettingsExtensionTest extends TestCase
{
    private function extension(string $tz, string $format): SettingsExtension
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnMap([
            ['app_timezone', $tz],
            ['app_date_format', $format],
        ]);

        return new SettingsExtension($settings);
    }

    public function testAppDateAppliesTimezoneAndFormat(): void
    {
        $ext = $this->extension('Europe/Zagreb', 'd.m.Y. H:i');
        // 12:00 UTC is 14:00 in Zagreb (CEST, summer).
        $date = new \DateTimeImmutable('2026-06-21 12:00:00', new \DateTimeZone('UTC'));

        self::assertSame('21.06.2026. 14:00', $ext->appDate($date));
    }

    public function testPerCallFormatOverride(): void
    {
        $ext = $this->extension('UTC', 'd.m.Y.');
        $date = new \DateTimeImmutable('2026-06-21 00:00:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-06-21', $ext->appDate($date, 'Y-m-d'));
    }

    public function testNullIsEmptyString(): void
    {
        self::assertSame('', $this->extension('UTC', 'Y-m-d')->appDate(null));
    }
}
