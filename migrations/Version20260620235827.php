<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620235827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category ADD featured_image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C13569D950 FOREIGN KEY (featured_image_id) REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_64C19C13569D950 ON category (featured_image_id)');
        $this->addSql('ALTER TABLE page ADD featured_image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE page ADD CONSTRAINT FK_140AB6203569D950 FOREIGN KEY (featured_image_id) REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_140AB6203569D950 ON page (featured_image_id)');
        $this->addSql('ALTER TABLE post ADD featured_image_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8D3569D950 FOREIGN KEY (featured_image_id) REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5A8A6C8D3569D950 ON post (featured_image_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C13569D950');
        $this->addSql('DROP INDEX IDX_64C19C13569D950 ON category');
        $this->addSql('ALTER TABLE category DROP featured_image_id');
        $this->addSql('ALTER TABLE page DROP FOREIGN KEY FK_140AB6203569D950');
        $this->addSql('DROP INDEX IDX_140AB6203569D950 ON page');
        $this->addSql('ALTER TABLE page DROP featured_image_id');
        $this->addSql('ALTER TABLE post DROP FOREIGN KEY FK_5A8A6C8D3569D950');
        $this->addSql('DROP INDEX IDX_5A8A6C8D3569D950 ON post');
        $this->addSql('ALTER TABLE post DROP featured_image_id');
    }
}
