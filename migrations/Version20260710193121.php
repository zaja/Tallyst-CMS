<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710193121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipping (Faza 1): fb_form.shipping_methods — the catalog keys a form offers at checkout.';
    }

    public function up(Schema $schema): void
    {
        // Per-form shipping offer: a JSON list of ShippingCatalog keys (nullable = no delivery). Additive.
        $this->addSql('ALTER TABLE fb_form ADD shipping_methods JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_form DROP shipping_methods');
    }
}
