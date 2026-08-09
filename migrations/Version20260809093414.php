<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Customer accounts, part 1 — the tables a buyer's account needs, and the link from a sale to it.
 *
 * `customer` holds a buyer who has PROVEN they hold their address by following a login link. There is
 * no password column and there never will be: the e-mail link IS the credential.
 *
 * `customer_login_request` holds one outstanding link, issued to an ADDRESS rather than to an account,
 * because at the moment of issue there is usually no account yet. The token is split: `selector` is
 * stored in the clear and is what we look up by, while only a hash of the verifier half is kept, so a
 * leaked database yields no usable links and lookup stays one indexed query instead of hashing every row.
 *
 * `fb_order.customer_id` is the hard link from a sale to its account. NULLABLE is the normal state, not
 * a defect — it means nobody has proven that address yet, and the order simply waits. ON DELETE SET NULL
 * because deleting an account must never delete sales records: the order history is the site owner's
 * business ledger, and a buyer closing their account cannot take it with them. Same reasoning that made
 * `form_id` SET NULL in Version20260807081500.
 *
 * `idx_order_customer_email` exists because every query this feature makes is "orders for this address",
 * and `fb_order` carried no index at all before now.
 *
 * NO BACKFILL, deliberately. Existing paid orders keep `customer_id` NULL and are picked up the first
 * time their buyer confirms a login link. Creating accounts here for every historical address would be
 * exactly the thing the design forbids — an account nobody proved.
 *
 * ⚠ The DOWN order matters and is not the generator's. Doctrine emitted `DROP TABLE customer` before
 * dropping the foreign key that points at it, which MySQL refuses. The constraint and column go first.
 */
final class Version20260809093414 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer accounts: customer + customer_login_request tables, fb_order.customer_id (SET NULL), index on fb_order.customer_email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(191) NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_81398E09E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer_login_request (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(32) NOT NULL, hashed_token VARCHAR(100) NOT NULL, email VARCHAR(191) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_4FDFE1E19692E25D (selector), INDEX idx_clr_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fb_order ADD customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A15CF53E9395C3F3 ON fb_order (customer_id)');
        $this->addSql('CREATE INDEX idx_order_customer_email ON fb_order (customer_email)');
    }

    public function down(Schema $schema): void
    {
        // Constraint first, then the column, and only then the table it referenced.
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E9395C3F3');
        $this->addSql('DROP INDEX IDX_A15CF53E9395C3F3 ON fb_order');
        $this->addSql('DROP INDEX idx_order_customer_email ON fb_order');
        $this->addSql('ALTER TABLE fb_order DROP customer_id');
        $this->addSql('DROP TABLE customer_login_request');
        $this->addSql('DROP TABLE customer');
    }
}
