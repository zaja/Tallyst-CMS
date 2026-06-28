<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628153608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add page.hero_position (left/right, default left) + page.hero_style (photo/light/dark, default photo) for the hero overlay layout.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE page ADD hero_position VARCHAR(8) DEFAULT \'left\' NOT NULL, ADD hero_style VARCHAR(8) DEFAULT \'photo\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE page DROP hero_position, DROP hero_style');
    }
}
