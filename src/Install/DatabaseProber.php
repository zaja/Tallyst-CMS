<?php

namespace App\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Opens a RAW DBAL connection straight from a DSN — independent of the kernel's compiled
 * container — so the installer can validate the credentials the user just typed (and probe
 * an already-installed DB for the guard) BEFORE writing .env.local or booting a fresh kernel.
 */
class DatabaseProber
{
    public function connect(string $dsn): Connection
    {
        // DBAL 4's DriverManager no longer derives the driver from a `url` param — it needs an
        // explicit `driver`/`driverClass` (else "The options driver or driverClass are mandatory").
        // DsnParser maps the DSN scheme (mysql/mariadb → pdo_mysql) to a full params array.
        // The Symfony container parses the same mysql:// DSN fine via its own config — this only
        // fixes the manual, out-of-container probe; the .env.local DATABASE_URL format is unchanged.
        $params = (new DsnParser([
            'mysql' => 'pdo_mysql',
            'mariadb' => 'pdo_mysql',
        ]))->parse($dsn);

        return DriverManager::getConnection($params);
    }

    /**
     * Throws on an unreachable server / bad credentials — drives the re-prompt loop.
     */
    public function ping(Connection $conn): void
    {
        $conn->executeQuery('SELECT 1');
    }

    /**
     * The server version, normalised for the DSN (so Doctrine doesn't have to auto-detect it).
     */
    public function detectServerVersion(Connection $conn): string
    {
        return DatabaseDsnBuilder::formatServerVersion((string) $conn->fetchOne('SELECT VERSION()'));
    }
}
