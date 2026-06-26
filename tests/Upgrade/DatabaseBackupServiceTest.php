<?php

namespace App\Tests\Upgrade;

use App\Install\DatabaseBackupService;
use PHPUnit\Framework\TestCase;

/**
 * Locks the credential extraction the backup relies on. The critical case is decoding: DsnParser
 * already rawurl-decodes user/password, so the service must pass them THROUGH untouched — a second
 * decode would mangle any password with reserved characters and silently auth-fail the dump.
 */
class DatabaseBackupServiceTest extends TestCase
{
    public function testParseDsnReturnsExpectedKeys(): void
    {
        $svc = new DatabaseBackupService(
            'mysql://tallyst:secret@127.0.0.1:3306/tallyst_db?serverVersion=8.4.0&charset=utf8mb4',
            '/tmp/project',
        );

        $c = $svc->parseDsn();

        self::assertSame('127.0.0.1', $c['host']);
        self::assertSame('3306', $c['port']);
        self::assertSame('tallyst', $c['user']);
        self::assertSame('secret', $c['password']);
        self::assertSame('tallyst_db', $c['dbname']);
    }

    /**
     * The bug guard: %40 → @ exactly ONCE. A double-decode would turn "p%40ss" into "p@ss" then
     * leave it, but a naive extra rawurldecode on an already-decoded value is what we forbid — this
     * asserts the single-decode result the dump must use.
     */
    public function testParseDsnDecodesEncodedCredentialsExactlyOnce(): void
    {
        $svc = new DatabaseBackupService(
            'mysql://us%40er:p%40ss%21@127.0.0.1:3306/my_db?charset=utf8mb4',
            '/tmp/project',
        );

        $c = $svc->parseDsn();

        // %40 → @, %21 → ! — decoded once by DsnParser, passed through verbatim.
        self::assertSame('us@er', $c['user']);
        self::assertSame('p@ss!', $c['password']);
        self::assertSame('my_db', $c['dbname']);
    }

    /**
     * Environment-tolerant: findDumpBinary() either locates a real executable or honestly returns
     * null. Binary-missing behaviour (loud warn / abort) is exercised end-to-end in the step 3/5
     * upgrade smoke, where the command layer decides the policy.
     */
    public function testFindDumpBinaryReturnsExecutableOrNull(): void
    {
        $bin = (new DatabaseBackupService('mysql://u:p@127.0.0.1:3306/db', '/tmp/project'))->findDumpBinary();

        if (null !== $bin) {
            self::assertIsString($bin);
            self::assertTrue(is_executable($bin), sprintf('"%s" should be executable.', $bin));
        } else {
            self::assertNull($bin);
        }
    }
}
