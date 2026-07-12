<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Faza 5 Komad 1: backfill fb_form.mor_provider = 'dodo' for every digital_mor form (Dodo is the sole MoR
 * provider). Every other type stays NULL. `form_type` is authoritative (Faza 4), so this is a plain UPDATE
 * — no JSON parsing. Existing MoR forms then carry a valid provider (the MorProviderMatchesType invariant),
 * behave IDENTICALLY (nothing consumes mor_provider until Komad 2), and stay editable in the builder.
 * Runs after the schema add (Version20260712194150). down() is a no-op (the column is dropped there).
 */
final class Version20260712194200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 5: backfill fb_form.mor_provider = dodo for digital_mor forms.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE fb_form SET mor_provider = 'dodo' WHERE form_type = 'digital_mor'");
    }

    public function down(Schema $schema): void
    {
        // Data-only backfill — not reversed (a full rollback drops the column via Version20260712194150::down()).
    }
}
