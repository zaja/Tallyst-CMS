<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712194150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Faza 5 Komad 1: fb_form.mor_provider — WHICH MoR provider a digital_mor form uses (backfilled next).';
    }

    public function up(Schema $schema): void
    {
        // Additive + nullable + reversible. The SEPARATE data migration (Version20260712194200) backfills
        // digital_mor forms to 'dodo' (the sole MoR provider). See PLAN-FAZA-5-MOR-PROVIDER.md §7.
        $this->addSql('ALTER TABLE fb_form ADD mor_provider VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_form DROP mor_provider');
    }
}
