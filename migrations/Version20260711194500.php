<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Faza 3 (Komad 3.5): backfill existing forms' tax_rate_key with the CONCRETE current default rate key.
 *
 * Before this, taxRateKey was NULL on every pre-existing form, meaning "the default rate". We now bind each
 * form to a concrete rate (predictability: changing the default in Settings must NOT retroactively change an
 * existing form). This writes the key TaxCatalog::default() resolves to RIGHT NOW into every NULL row:
 *   - the entry flagged default (the first flagged) in the saved `tax_rates` JSON, else the first valid row;
 *   - the lazy default key ('default') when `tax_rates` is empty/unset (the legacy PDV/25 fallback) — that
 *     key persists across the admin's first Tax-tab save, so the reference stays valid.
 * Robust in the lazy-seed state (never breaks when `tax_rates` isn't saved yet). Harmless on free/MoR forms
 * (they ignore taxRateKey). See PLAN-FAZA-3-POREZ.md §3, §9.
 */
final class Version20260711194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 3: backfill fb_form.tax_rate_key (NULL → the concrete current default rate key).';
    }

    public function up(Schema $schema): void
    {
        $key = $this->resolveDefaultKey();
        $this->addSql('UPDATE fb_form SET tax_rate_key = ? WHERE tax_rate_key IS NULL', [$key]);
    }

    public function down(Schema $schema): void
    {
        // Data-only backfill — not reversed (we didn't record which rows were NULL). A full rollback drops
        // the column entirely (Version20260711192716::down()), so no data is stranded.
    }

    /**
     * The key TaxCatalog::default() currently resolves to — replicated in raw SQL/PHP (a migration can't
     * use the service). Mirrors TaxCatalog: first row flagged default wins, else the first valid row (a row
     * with a non-empty key AND name), else the lazy default key.
     */
    private function resolveDefaultKey(): string
    {
        $key = \Tallyst\FormBuilder\Service\TaxCatalog::DEFAULT_KEY;

        $json = $this->connection->fetchOne("SELECT value FROM setting WHERE name = 'tax_rates'");
        if (!\is_string($json) || '' === $json) {
            return $key;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return $key;
        }

        $firstValid = null;
        $marked = null;
        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $k = trim((string) ($row['key'] ?? ''));
            $n = trim((string) ($row['name'] ?? ''));
            if ('' === $k || '' === $n) {
                continue;
            }
            if (null === $firstValid) {
                $firstValid = $k;
            }
            if (null === $marked && !empty($row['default'])) {
                $marked = $k;
            }
        }

        return $marked ?? $firstValid ?? $key;
    }
}
