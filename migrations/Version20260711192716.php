<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711192716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 3: per-form tax rate — fb_form.tax_rate_key (nullable; NULL = default rate, backward-compatible).';
    }

    public function up(Schema $schema): void
    {
        // Additive + nullable + reversible: existing forms get NULL → the catalog default rate → charge
        // identically to before (one global rate). See PLAN-FAZA-3-POREZ.md §9.
        $this->addSql('ALTER TABLE fb_form ADD tax_rate_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_form DROP tax_rate_key');
    }
}
