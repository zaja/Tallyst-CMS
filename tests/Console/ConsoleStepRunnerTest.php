<?php

namespace App\Tests\Console;

use App\Console\ConsoleStepRunner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the child-env contract: the .env-managed keys MUST be stripped (false) so a fresh-kernel
 * subprocess re-reads them from .env.local instead of inheriting the parent's frozen values.
 * run() itself spawns a real subprocess, so it's covered by the install/upgrade smoke, not here.
 */
class ConsoleStepRunnerTest extends TestCase
{
    private const STRIPPED_KEYS = [
        'DATABASE_URL',
        'APP_SECRET',
        'DEFAULT_URI',
        'ORDER_ADMIN_EMAIL',
        'SETTINGS_ENCRYPTION_KEY',
        'APP_ENV',
        'APP_DEBUG',
    ];

    public function testChildEnvStripsAllManagedKeys(): void
    {
        $env = (new ConsoleStepRunner('/tmp/project'))->childEnv();

        foreach (self::STRIPPED_KEYS as $key) {
            self::assertArrayHasKey($key, $env, sprintf('"%s" must be present in the child env.', $key));
            self::assertFalse($env[$key], sprintf('"%s" must be stripped (false) so the child re-reads it.', $key));
        }

        self::assertCount(\count(self::STRIPPED_KEYS), $env, 'No keys beyond the stripped set without extras.');
    }

    public function testChildEnvMergesExtras(): void
    {
        $env = (new ConsoleStepRunner('/tmp/project'))->childEnv(['TALLYST_ADMIN_PASSWORD' => 's3cret']);

        self::assertSame('s3cret', $env['TALLYST_ADMIN_PASSWORD']);
        // Extras never weaken the strip contract.
        self::assertFalse($env['DATABASE_URL']);
        self::assertCount(\count(self::STRIPPED_KEYS) + 1, $env);
    }

    public function testExtrasCanOverrideAStrippedKey(): void
    {
        // array_merge semantics: an explicit extra wins over the default strip.
        $env = (new ConsoleStepRunner('/tmp/project'))->childEnv(['APP_ENV' => 'test']);

        self::assertSame('test', $env['APP_ENV']);
    }
}
