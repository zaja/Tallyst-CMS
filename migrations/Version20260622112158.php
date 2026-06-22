<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260622112158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE page ADD hero_enabled TINYINT NOT NULL, ADD hero_title VARCHAR(191) DEFAULT NULL, ADD hero_text LONGTEXT DEFAULT NULL, ADD hero_cta_label VARCHAR(191) DEFAULT NULL, ADD hero_cta_url VARCHAR(255) DEFAULT NULL, ADD hero_image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE page ADD CONSTRAINT FK_140AB62098BB94C5 FOREIGN KEY (hero_image_id) REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_140AB62098BB94C5 ON page (hero_image_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE page DROP FOREIGN KEY FK_140AB62098BB94C5');
        $this->addSql('DROP INDEX IDX_140AB62098BB94C5 ON page');
        $this->addSql('ALTER TABLE page DROP hero_enabled, DROP hero_title, DROP hero_text, DROP hero_cta_label, DROP hero_cta_url, DROP hero_image_id');
    }
}
