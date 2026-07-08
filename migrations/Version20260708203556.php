<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260708203556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dodo_pending_license (id INT AUTO_INCREMENT NOT NULL, payment_id VARCHAR(255) NOT NULL, license_key VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_dodo_pending_payment (payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fb_order ADD customer_name VARCHAR(255) DEFAULT NULL, ADD customer_phone VARCHAR(64) DEFAULT NULL, ADD invoice_url VARCHAR(1024) DEFAULT NULL, ADD license_key VARCHAR(255) DEFAULT NULL, ADD dodo_tax_minor INT DEFAULT NULL, ADD dodo_total_minor INT DEFAULT NULL, ADD dodo_settlement_minor INT DEFAULT NULL, ADD dodo_settlement_currency VARCHAR(3) DEFAULT NULL, ADD provider_metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE dodo_pending_license');
        $this->addSql('ALTER TABLE fb_order DROP customer_name, DROP customer_phone, DROP invoice_url, DROP license_key, DROP dodo_tax_minor, DROP dodo_total_minor, DROP dodo_settlement_minor, DROP dodo_settlement_currency, DROP provider_metadata');
    }
}
