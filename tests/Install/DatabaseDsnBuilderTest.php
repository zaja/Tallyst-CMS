<?php

namespace App\Tests\Install;

use App\Install\DatabaseDsnBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseDsnBuilderTest extends TestCase
{
    public function testBuildsAMysqlDsnWithServerVersionAndCharset(): void
    {
        $dsn = (new DatabaseDsnBuilder())->build([
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'tallystcms',
            'user' => 'tallystcms',
            'password' => 'secret',
            'serverVersion' => '8.4.0',
        ]);

        self::assertSame('mysql://tallystcms:secret@127.0.0.1:3306/tallystcms?serverVersion=8.4.0&charset=utf8mb4', $dsn);
    }

    public function testUrlEncodesSpecialCharactersInCredentials(): void
    {
        $dsn = (new DatabaseDsnBuilder())->build([
            'host' => 'db.example.com',
            'port' => '3307',
            'name' => 'my db',
            'user' => 'us@r',
            'password' => 'p@ss:w/rd#&$',
            'serverVersion' => '10.11.2-MariaDB',
        ]);

        // Credentials + name are percent-encoded; the query keeps a literal '&' separator.
        self::assertStringStartsWith('mysql://us%40r:p%40ss%3Aw%2Frd%23%26%24@db.example.com:3307/my%20db?', $dsn);
        self::assertStringContainsString('serverVersion=10.11.2-MariaDB', $dsn);
        self::assertStringContainsString('&charset=utf8mb4', $dsn);
    }

    public function testOmitsServerVersionWhenEmpty(): void
    {
        $dsn = (new DatabaseDsnBuilder())->build([
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'app',
            'user' => 'app',
            'password' => '',
        ]);

        self::assertStringNotContainsString('serverVersion', $dsn);
        self::assertStringContainsString('charset=utf8mb4', $dsn);
    }

    #[DataProvider('serverVersionCases')]
    public function testFormatsServerVersion(string $raw, string $expected): void
    {
        self::assertSame($expected, DatabaseDsnBuilder::formatServerVersion($raw));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverVersionCases(): array
    {
        return [
            'plain mysql' => ['8.4.0', '8.4.0'],
            'mysql with distro suffix' => ['8.0.36-0ubuntu0.22.04.1', '8.0.36'],
            'mariadb full' => ['10.11.2-MariaDB-1:10.11.2+maria~ubu2204', '10.11.2-MariaDB'],
            'mariadb replication-prefixed' => ['5.5.5-10.11.2-MariaDB-1:10.11.2+maria', '10.11.2-MariaDB'],
            'empty' => ['', ''],
            'unparseable' => ['unknown', 'unknown'],
        ];
    }
}
