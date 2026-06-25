<?php

namespace App\Tests\Install;

use App\Install\DatabaseDsnBuilder;
use App\Install\DatabaseProber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as PdoMySqlDriver;
use PHPUnit\Framework\TestCase;

/**
 * connect() does NOT touch a real server (DriverManager is lazy), but it DOES validate the
 * params synchronously — so building a Connection from a mysql:// DSN proves the driver was
 * resolved. This guards the DBAL-4 regression where DriverManager::getConnection(['url'=>…])
 * threw "The options driver or driverClass are mandatory…" (only surfaced on a real install,
 * because the isolated DsnBuilder test never built a Connection).
 */
class DatabaseProberTest extends TestCase
{
    public function testConnectResolvesThePdoMysqlDriverFromAMysqlDsn(): void
    {
        $dsn = (new DatabaseDsnBuilder())->build([
            'host' => '127.0.0.1', 'port' => 3306, 'name' => 'tallysttest',
            'user' => 'tallysttest', 'password' => 'p@ss w/rd#&$', 'serverVersion' => '8.4.0',
        ]);

        $conn = (new DatabaseProber())->connect($dsn);

        self::assertInstanceOf(Connection::class, $conn);
        self::assertInstanceOf(PdoMySqlDriver::class, $conn->getDriver(), 'scheme mysql → pdo_mysql driver');
        // The special-char password round-tripped through rawurlencode/decode intact.
        self::assertSame('p@ss w/rd#&$', $conn->getParams()['password'] ?? null);
    }

    public function testConnectWorksWithoutServerVersionInTheProbeDsn(): void
    {
        // The wizard probes with a serverVersion-less DSN before detecting it via SELECT VERSION().
        $dsn = (new DatabaseDsnBuilder())->build([
            'host' => 'db.example.com', 'port' => '3307', 'name' => 'app',
            'user' => 'app', 'password' => 'secret',
        ]);

        $conn = (new DatabaseProber())->connect($dsn);

        self::assertInstanceOf(PdoMySqlDriver::class, $conn->getDriver());
    }
}
