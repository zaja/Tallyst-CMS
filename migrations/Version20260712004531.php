<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712004531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 4 Komad 1: fb_form.form_type — the explicit remembered form type (default messages; backfilled next).';
    }

    public function up(Schema $schema): void
    {
        // Additive + reversible. DEFAULT 'messages' makes every existing row inert first; the SEPARATE data
        // migration (Version20260712004600) then sets each form's real type from its current shape. See
        // PLAN-FAZA-4-WIZARD.md §7.
        $this->addSql('ALTER TABLE fb_form ADD form_type VARCHAR(16) DEFAULT \'messages\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_form DROP form_type');
    }
}
