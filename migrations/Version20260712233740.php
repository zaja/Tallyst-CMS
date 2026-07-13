<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Faza 6 K1 — data backfill: turn every existing single-product MoR form into a one-entry sellable-units
 * list, so a MoR form's list-with-one-entry behaves IDENTICALLY to today's single dodoProductId.
 *
 * For each digital_mor form that has a dodo_product_id, mor_units becomes:
 *   [{ "label": "Standard", "unitId": <dodo_product_id>, "priceMinor": <price_minor>, "currency": <currency> }]
 * (priceMinor/currency are the DISPLAY cache; the provider still charges its own price.) The label is
 * cosmetic — a single unit is never shown as a choice (the buyer sees a choice only when count > 1), and
 * the checkout sets variantLabel only when >1, so a single-unit MoR order stays variantLabel = NULL.
 *
 * dodo_product_id is LEFT INTACT (dormant, still the fallback for sellableUnits()). Runs AFTER the schema
 * migration (later timestamp). Idempotent: only fills where mor_units IS NULL. JSON built with
 * JSON_ARRAY/JSON_OBJECT so values are escaped natively (no string concatenation).
 */
final class Version20260712233740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 6: backfill existing MoR forms → mor_units single-entry list (behaviour identical)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE fb_form
            SET mor_units = JSON_ARRAY(JSON_OBJECT(
                'label', 'Standard',
                'unitId', dodo_product_id,
                'priceMinor', price_minor,
                'currency', currency
            ))
            WHERE form_type = 'digital_mor'
              AND dodo_product_id IS NOT NULL
              AND dodo_product_id <> ''
              AND mor_units IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverse the backfill only (the schema migration drops the column). dodo_product_id was untouched,
        // so the old single-product behaviour is fully restored.
        $this->addSql("UPDATE fb_form SET mor_units = NULL WHERE form_type = 'digital_mor'");
    }
}
