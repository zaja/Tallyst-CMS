<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711073054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipping countries (Faza 2): fb_form.allowed_shipping_countries — per-form ISO alpha-2 allow-list (null/empty = everywhere).';
    }

    public function up(Schema $schema): void
    {
        // Per-form shipping-country gate: a JSON list of ISO alpha-2 codes (nullable = ships everywhere). Additive.
        $this->addSql('ALTER TABLE fb_form ADD allowed_shipping_countries JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_form DROP allowed_shipping_countries');
    }
}
