<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710202016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipping (Faza 1): fb_order.shipping_label + shipping_amount_minor (the chosen method + its amount, folded into amount_minor). Null = no delivery / MoR order.';
    }

    public function up(Schema $schema): void
    {
        // The chosen delivery method snapshot + its amount (already included in amount_minor). Additive, nullable.
        $this->addSql('ALTER TABLE fb_order ADD shipping_label VARCHAR(255) DEFAULT NULL, ADD shipping_amount_minor INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_order DROP shipping_label, DROP shipping_amount_minor');
    }
}
