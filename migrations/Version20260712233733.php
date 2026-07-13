<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712233733 extends AbstractMigration
{
    public function getDescription(): string
    {
        // Faza 6 K1: MoR sellable-units list on the form + the chosen unit id on the order (both dormant
        // this step — nobody reads them yet). Data-backfill of existing MoR forms is a SEPARATE migration.
        return 'Faza 6: fb_form.mor_units + fb_order.provider_unit_id (schema only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_form ADD mor_units JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE fb_order ADD provider_unit_id VARCHAR(191) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_form DROP mor_units');
        $this->addSql('ALTER TABLE fb_order DROP provider_unit_id');
    }
}
