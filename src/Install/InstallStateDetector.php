<?php

namespace App\Install;

use Doctrine\DBAL\Connection;

/**
 * Decides whether Tallyst is already installed. The pure text predicate
 * (envLocalHasDatabaseUrl) is unit-testable; the DB probes take a live DBAL connection
 * (built by DatabaseProber from the .env.local DSN) and are smoke-tested.
 */
class InstallStateDetector
{
    public function envLocalHasDatabaseUrl(string $envLocalContents): bool
    {
        return (bool) preg_match('/^DATABASE_URL=\S/m', $envLocalContents);
    }

    /**
     * A schema that already holds the core tables — a strong "already installed" signal.
     */
    public function coreTablesExist(Connection $conn): bool
    {
        try {
            $tables = $conn->createSchemaManager()->listTableNames();

            return \in_array('user', $tables, true) || \in_array('page', $tables, true);
        } catch (\Throwable) {
            return false;
        }
    }

    public function anyUserExists(Connection $conn): bool
    {
        try {
            return (int) $conn->fetchOne('SELECT COUNT(*) FROM `user`') > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
