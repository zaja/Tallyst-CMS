<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Tallyst\FormBuilder\Service\FormTypeDeriver;

/**
 * Faza 4 Komad 1: backfill fb_form.form_type from each form's CURRENT shape, so the explicit type
 * reproduces today's guessed behaviour EXACTLY (isProduct + isMerchantOfRecordForm) — no form changes.
 * The derivation rule lives in FormTypeDeriver (shared with its test, no drift) — see PLAN-FAZA-4-WIZARD.md §3.
 *
 * Runs after the schema add (Version20260712004531) that defaulted every row to 'messages'. Audited safe:
 * no degenerate forms exist (a MoR form with no price/variants, or a config-but-price-0 form). down() is a
 * no-op (the column is dropped by the schema migration's down()).
 */
final class Version20260712004600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 4: backfill fb_form.form_type (messages/physical/digital/digital_mor) from each form\'s current shape.';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, price_minor, variants, dodo_product_id, allowed_payment_methods, shipping_methods FROM fb_form',
        );

        foreach ($rows as $row) {
            $type = FormTypeDeriver::derive(
                null !== $row['price_minor'] ? (int) $row['price_minor'] : null,
                $this->jsonList($row['variants'] ?? null),
                null !== ($row['dodo_product_id'] ?? null) ? (string) $row['dodo_product_id'] : null,
                $this->jsonList($row['allowed_payment_methods'] ?? null),
                $this->jsonList($row['shipping_methods'] ?? null),
            );
            $this->addSql('UPDATE fb_form SET form_type = ? WHERE id = ?', [$type->value, (int) $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // Data-only backfill — not reversed (a full rollback drops the column via Version20260712004531::down()).
    }

    /** Decode a JSON column to a list, tolerating NULL / '' / 'null' / non-array. @return array<int, mixed> */
    private function jsonList(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
