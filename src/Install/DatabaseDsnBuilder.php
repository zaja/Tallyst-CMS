<?php

namespace App\Install;

/**
 * Builds a Doctrine MySQL/MariaDB DATABASE_URL from discrete parts and normalises a raw
 * `SELECT VERSION()` string into a DSN-safe serverVersion. Pure (no I/O) → fully unit-testable.
 *
 * The project targets MySQL/MariaDB (CloudPanel); the DSN scheme is always `mysql://`.
 */
class DatabaseDsnBuilder
{
    /**
     * @param array{
     *   host: string,
     *   port: int|string,
     *   name: string,
     *   user: string,
     *   password: string,
     *   serverVersion?: string|null,
     *   charset?: string
     * } $parts
     */
    public function build(array $parts): string
    {
        $user = rawurlencode($parts['user']);
        $pass = rawurlencode($parts['password']);
        $host = $parts['host'];
        $port = (string) $parts['port'];
        $name = rawurlencode($parts['name']);

        $query = [];
        if (!empty($parts['serverVersion'])) {
            $query['serverVersion'] = $parts['serverVersion'];
        }
        $query['charset'] = $parts['charset'] ?? 'utf8mb4';

        return sprintf('mysql://%s:%s@%s:%s/%s?%s', $user, $pass, $host, $port, $name, http_build_query($query, '', '&', \PHP_QUERY_RFC3986));
    }

    /**
     * Normalise a raw `SELECT VERSION()` string to a value Doctrine accepts in the DSN.
     *  - MariaDB: "10.11.2-MariaDB-1:10.11.2+maria~ubu2204" (or replication-prefixed
     *    "5.5.5-10.11.2-MariaDB-…") → "10.11.2-MariaDB".
     *  - MySQL: "8.4.0", "8.0.36-0ubuntu0.22.04.1" → "8.4.0".
     */
    public static function formatServerVersion(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }

        if (false !== stripos($raw, 'mariadb')) {
            // The x.y.z immediately preceding the "MariaDB" token is the real server version
            // (skips the legacy "5.5.5-" replication prefix).
            if (preg_match('/(\d+\.\d+\.\d+)\D*mariadb/i', $raw, $m)) {
                return $m[1].'-MariaDB';
            }
            if (preg_match('/(\d+\.\d+\.\d+)/', $raw, $m)) {
                return $m[1].'-MariaDB';
            }

            return $raw;
        }

        if (preg_match('/(\d+\.\d+\.\d+)/', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }
}
