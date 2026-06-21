<?php

namespace App\Twig;

use App\Settings\SettingsManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Exposes settings to templates: `setting('key')` reads a typed value, and `app_date()`
 * formats a date with the configured timezone + date format (so the Lokalizacija settings
 * actually drive the front instead of the hardcoded |date filters).
 */
class SettingsExtension extends AbstractExtension
{
    public function __construct(private readonly SettingsManager $settings)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('setting', $this->setting(...)),
            new TwigFunction('app_date', $this->appDate(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('app_date', $this->appDate(...)),
        ];
    }

    public function setting(string $key): mixed
    {
        return $this->settings->get($key);
    }

    /**
     * Format a date with the configured timezone + format (override format per-call if given).
     */
    public function appDate(\DateTimeInterface|string|null $value, ?string $format = null): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        try {
            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return '';
        }

        $timezone = (string) ($this->settings->get('app_timezone') ?: 'UTC');
        try {
            $date = $date->setTimezone(new \DateTimeZone($timezone));
        } catch (\Exception) {
            // keep original timezone on a bad config
        }

        $fmt = $format ?? (string) ($this->settings->get('app_date_format') ?: 'Y-m-d');

        return $date->format($fmt);
    }
}
