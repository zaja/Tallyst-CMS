<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A checkout that never completed becomes a state of its own, instead of sitting in "awaiting
 * payment" forever.
 *
 * `abandoned_at` records WHEN an order was declared not completed, and is never cleared — not even
 * when a slow payment settles afterwards and the order goes back to paid. That is deliberate: the
 * owner has to keep seeing how many people walk away and how many come back, and clearing the
 * column on a late payment would erase exactly that.
 *
 * ⚠ THE SECOND STATEMENT IS THE IMPORTANT ONE, AND IT IS NOT ABOUT SCHEMA. It stamps the moment
 * this feature became active on THIS site. From here on, an order that misses the deadline gets an
 * e-mail offering the buyer a way to finish; anything created BEFORE this instant is closed
 * silently, whatever its age.
 *
 * The reason is a real hazard, not tidiness: without the stamp, the first sweep after an upgrade
 * would mail every customer who ever abandoned a basket — people who walked away months before the
 * shop was capable of noticing. A time WINDOW ("younger than 48 hours") does not prevent it, because
 * an upgrade can happen at any point relative to those orders. Only knowing when the shop started
 * watching does.
 */
final class Version20260814185613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orders: an abandoned checkout becomes its own state (abandoned_at), and the moment failure tracking started is stamped so no upgrade mails historic customers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_order ADD abandoned_at DATETIME DEFAULT NULL');

        // Written only if absent, so a re-run or a partially applied upgrade cannot move the line
        // forward and silence orders that should have been mailed.
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->addSql(<<<SQL
            INSERT INTO setting (name, value)
            SELECT 'order_failure_tracking_since', '{$now}'
            WHERE NOT EXISTS (SELECT 1 FROM setting s WHERE s.name = 'order_failure_tracking_since')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fb_order DROP abandoned_at');
        $this->addSql("DELETE FROM setting WHERE name = 'order_failure_tracking_since'");
    }
}
