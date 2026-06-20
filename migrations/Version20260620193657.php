<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620193657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fb_field (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, label VARCHAR(191) NOT NULL, field_key VARCHAR(100) NOT NULL, placeholder VARCHAR(191) DEFAULT NULL, required TINYINT NOT NULL, options JSON NOT NULL, position INT NOT NULL, conditions JSON NOT NULL, form_id INT NOT NULL, INDEX IDX_F8023FE5FF69B7D (form_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fb_form (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(191) NOT NULL, slug VARCHAR(191) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, price NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_F9B972B9989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fb_submission (id INT AUTO_INCREMENT NOT NULL, data JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, form_id INT NOT NULL, INDEX IDX_B6C2AAF15FF69B7D (form_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fb_field ADD CONSTRAINT FK_F8023FE5FF69B7D FOREIGN KEY (form_id) REFERENCES fb_form (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fb_submission ADD CONSTRAINT FK_B6C2AAF15FF69B7D FOREIGN KEY (form_id) REFERENCES fb_form (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_field DROP FOREIGN KEY FK_F8023FE5FF69B7D');
        $this->addSql('ALTER TABLE fb_submission DROP FOREIGN KEY FK_B6C2AAF15FF69B7D');
        $this->addSql('DROP TABLE fb_field');
        $this->addSql('DROP TABLE fb_form');
        $this->addSql('DROP TABLE fb_submission');
    }
}
