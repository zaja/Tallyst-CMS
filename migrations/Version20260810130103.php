<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Customer → Member: the account is for MEMBERS of the site, not only for buyers.
 *
 * Purchases are one of the things a site may show a member, not the reason the account exists —
 * profile, post subscriptions and comments hang off the same seam. Renamed BEFORE the release that
 * would have made `customer` a public promise to site owners and add-on authors.
 *
 * ⚠ WHAT IS *NOT* RENAMED, AND WHY. `fb_order.customer_email`, `customer_name`, `customer_phone`,
 * `customer_country`, `customer_ip` and `customer_vat_id` all stay. They are facts about the BUYER
 * OF THAT ORDER — which address it was paid with, in whose name, from which country — and they stay
 * true whether or not a member account ever exists. Renaming them would claim the order belongs to a
 * member; it belongs to a purchase. Only `customer_id`, which actually points at the account, moves.
 *
 * ⚠ THIS MIGRATION RENAMES, IT DOES NOT RECREATE. `doctrine:migrations:diff` generated
 * `DROP TABLE customer` + `CREATE TABLE member`, which would have destroyed every account and
 * orphaned every order already attached to one. `RENAME TABLE` and `CHANGE` keep the rows.
 *
 * No data changes: no row is inserted, updated or removed by this migration.
 */
final class Version20260810130103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer → Member: rename customer/customer_login_request tables and fb_order.customer_id (data preserved; order-level customer_* buyer fields untouched)';
    }

    public function up(Schema $schema): void
    {
        // The foreign key has to go first — it references the table being renamed.
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E9395C3F3');
        $this->addSql('DROP INDEX IDX_A15CF53E9395C3F3 ON fb_order');

        $this->addSql('RENAME TABLE customer TO `member`');
        $this->addSql('RENAME TABLE customer_login_request TO member_login_request');

        // Index names are derived from the table name, so they move with it or schema:validate drifts.
        $this->addSql('ALTER TABLE `member` RENAME INDEX UNIQ_81398E09E7927C74 TO UNIQ_70E4FA78E7927C74');
        $this->addSql('ALTER TABLE member_login_request RENAME INDEX UNIQ_4FDFE1E19692E25D TO UNIQ_C8AC296E9692E25D');
        $this->addSql('ALTER TABLE member_login_request RENAME INDEX idx_clr_email TO idx_mlr_email');

        // CHANGE keeps the column's values — every order already attached to an account stays attached.
        $this->addSql('ALTER TABLE fb_order CHANGE customer_id member_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_A15CF53E7597D3FE ON fb_order (member_id)');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E7597D3FE FOREIGN KEY (member_id) REFERENCES `member` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E7597D3FE');
        $this->addSql('DROP INDEX IDX_A15CF53E7597D3FE ON fb_order');

        $this->addSql('ALTER TABLE `member` RENAME INDEX UNIQ_70E4FA78E7927C74 TO UNIQ_81398E09E7927C74');
        $this->addSql('ALTER TABLE member_login_request RENAME INDEX UNIQ_C8AC296E9692E25D TO UNIQ_4FDFE1E19692E25D');
        $this->addSql('ALTER TABLE member_login_request RENAME INDEX idx_mlr_email TO idx_clr_email');

        $this->addSql('RENAME TABLE `member` TO customer');
        $this->addSql('RENAME TABLE member_login_request TO customer_login_request');

        $this->addSql('ALTER TABLE fb_order CHANGE member_id customer_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_A15CF53E9395C3F3 ON fb_order (customer_id)');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
    }
}
