<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623163608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_order ADD tax_amount_minor INT DEFAULT NULL, ADD net_amount_minor INT DEFAULT NULL, ADD tax_rate NUMERIC(5, 2) DEFAULT NULL, ADD tax_name VARCHAR(50) DEFAULT NULL, ADD customer_country VARCHAR(100) DEFAULT NULL, ADD customer_ip VARCHAR(45) DEFAULT NULL, ADD customer_vat_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_order DROP tax_amount_minor, DROP net_amount_minor, DROP tax_rate, DROP tax_name, DROP customer_country, DROP customer_ip, DROP customer_vat_id');
    }
}
