<?php

namespace App\Tests\Settings;

use App\Settings\LocaleSubscriber;
use App\Settings\SettingsManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class LocaleSubscriberTest extends TestCase
{
    public function testAppliesConfiguredLocaleToTheRequest(): void
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturnMap([
            ['app_locale', 'hr'],
            ['app_timezone', 'Europe/Zagreb'],
        ]);

        $request = new Request();
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        (new LocaleSubscriber($settings))($event);

        self::assertSame('hr', $request->getLocale());
        self::assertSame('Europe/Zagreb', date_default_timezone_get());
    }

    public function testSubRequestIsIgnored(): void
    {
        $settings = $this->createMock(SettingsManager::class);
        $settings->expects(self::never())->method('get');

        $request = new Request();
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST);

        (new LocaleSubscriber($settings))($event);
    }
}
