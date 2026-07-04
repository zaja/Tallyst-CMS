<?php

namespace App\Tests\Console;

use App\Console\ConsoleStepRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Locks the child-env contract: the .env-managed keys MUST be stripped (false) so a fresh-kernel
 * subprocess re-reads them from .env.local instead of inheriting the parent's frozen values.
 * run()'s happy path spawns a real subprocess (covered by the install/upgrade smoke); here we lock
 * the child-env contract, the SAPI binary resolution, and the test-env spawn guard.
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

    /**
     * Defense-in-depth: run() must REFUSE to spawn a subprocess when the injected kernel env is
     * 'test' — the child re-reads .env.local (DEV DB) and would mutate dev, not the isolated test DB.
     * (The DI-autowired instance gets the real 'test' env in functional tests; a manual
     * `new ConsoleStepRunner($dir)` defaults to 'prod', so the upgrade test's tmpDir spawn is
     * unaffected.)
     */
    public function testRunRefusesToSpawnInTestEnv(): void
    {
        $runner = new ConsoleStepRunner('/tmp/project', 'test');
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must not spawn subprocesses in the test environment');
        $runner->run($io, ['cache:clear'], $runner->childEnv());
    }

    /**
     * CLI SAPI → PHP_BINARY is the CLI binary; use it untouched (install/upgrade path). The finders
     * must NOT even be consulted.
     */
    public function testResolvePhpBinaryUsesPhpBinaryUnderCli(): void
    {
        foreach (['cli', 'cli-server', 'phpdbg'] as $sapi) {
            $binary = ConsoleStepRunner::resolvePhpBinary(
                $sapi,
                'php8.5',
                function (): ?string { self::fail('versioned finder must not run under CLI'); },
                function (): string|false { self::fail('php finder must not run under CLI'); },
                '/usr/bin/php8.5',
            );

            self::assertSame('/usr/bin/php8.5', $binary, sprintf('SAPI "%s" must use PHP_BINARY.', $sapi));
        }
    }

    /**
     * Non-CLI SAPI (php-fpm) → PHP_BINARY is the fpm binary (can't run scripts), so resolve the
     * VERSION-MATCHED CLI binary via the versioned name — NOT a bare `php` (wrong version gotcha).
     */
    public function testResolvePhpBinaryPrefersVersionedUnderFpm(): void
    {
        $binary = ConsoleStepRunner::resolvePhpBinary(
            'fpm-fcgi',
            'php8.5',
            fn (string $name): ?string => '/usr/bin/'.$name, // ExecutableFinder found php8.5
            fn (): string|false => self::fail('fallback must not run when the versioned binary is found'),
            '/usr/sbin/php-fpm8.5',
        );

        self::assertSame('/usr/bin/php8.5', $binary);
    }

    /**
     * Non-CLI, versioned binary missing → PhpExecutableFinder fallback, before the PHP_BINARY last
     * resort.
     */
    public function testResolvePhpBinaryFallsBackToPhpFinderUnderFpm(): void
    {
        $binary = ConsoleStepRunner::resolvePhpBinary(
            'fpm-fcgi',
            'php8.5',
            fn (string $name): ?string => null, // versioned not found
            fn (): string|false => '/opt/php/bin/php', // PhpExecutableFinder found something
            '/usr/sbin/php-fpm8.5',
        );

        self::assertSame('/opt/php/bin/php', $binary);
    }

    /**
     * Non-CLI, nothing resolvable → PHP_BINARY as the last resort (best effort; never empty).
     */
    public function testResolvePhpBinaryLastResortIsPhpBinary(): void
    {
        $binary = ConsoleStepRunner::resolvePhpBinary(
            'fpm-fcgi',
            'php8.5',
            fn (string $name): ?string => null,
            fn (): string|false => false,
            '/usr/sbin/php-fpm8.5',
        );

        self::assertSame('/usr/sbin/php-fpm8.5', $binary);
    }
}
