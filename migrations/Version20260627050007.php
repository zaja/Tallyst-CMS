<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260627050007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_demo flag (default false) to demo-seedable entities so the uninstaller can remove exactly the demo set.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE fb_form ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE fb_order ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE fb_submission ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE media ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE menu ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE page ADD is_demo TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE post ADD is_demo TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE category DROP is_demo');
        $this->addSql('ALTER TABLE fb_form DROP is_demo');
        $this->addSql('ALTER TABLE fb_order DROP is_demo');
        $this->addSql('ALTER TABLE fb_submission DROP is_demo');
        $this->addSql('ALTER TABLE media DROP is_demo');
        $this->addSql('ALTER TABLE menu DROP is_demo');
        $this->addSql('ALTER TABLE page DROP is_demo');
        $this->addSql('ALTER TABLE post DROP is_demo');
    }
}
