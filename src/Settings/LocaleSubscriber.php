<?php

namespace App\Settings;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes the `app_locale` / `app_timezone` settings actually take effect: on every main
 * request it sets the request locale (so translations + Intl follow the configured
 * language) and the PHP default timezone (so date rendering is consistent). Runs at high
 * priority so it wins over the framework's default-locale wiring; date FORMAT is applied
 * per-render by the app_date() Twig helper.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 100)]
class LocaleSubscriber
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $locale = (string) $this->settings->get('app_locale');
        if ('' !== $locale) {
            $request = $event->getRequest();
            $request->setLocale($locale);
            $request->setDefaultLocale($locale);
        }

        $timezone = (string) $this->settings->get('app_timezone');
        if ('' !== $timezone && \in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            date_default_timezone_set($timezone);
        }
    }
}
