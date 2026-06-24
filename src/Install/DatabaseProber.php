<?php

namespace App\Install;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Opens a RAW DBAL connection straight from a DSN — independent of the kernel's compiled
 * container — so the installer can validate the credentials the user just typed (and probe
 * an already-installed DB for the guard) BEFORE writing .env.local or booting a fresh kernel.
 */
class DatabaseProber
{
    public function connect(string $dsn): Connection
    {
        return DriverManager::getConnection(['url' => $dsn]);
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
