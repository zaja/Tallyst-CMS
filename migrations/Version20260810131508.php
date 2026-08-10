<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per remembered member sign-in: this member, on this device, since this moment.
 *
 * A member stays signed in for 90 days from LAST USE, so somebody who comes back weekly never has
 * to ask for a new link. The row is what makes that possible, and it is deliberately a row per
 * sign-in rather than a flag on the member: signing out on a phone must not sign out the laptop,
 * and a later "your devices" screen is then a read of this table rather than a change to how
 * sign-ins are stored — which would mean re-issuing everybody's.
 *
 * `user_agent` and `ip_address` are recorded now although nothing displays them yet, for the same
 * reason: adding a column later is easy, but changing the shape of live sign-ins is not.
 *
 * ⚠ Signing out DELETES the row. A cookie still matching a live row remains a working sign-in.
 */
final class Version20260810131508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Member sessions: one row per remembered sign-in (device + address recorded), 90 days from last use';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_session (series VARCHAR(88) NOT NULL, token_value VARCHAR(88) NOT NULL, user_identifier VARCHAR(191) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, INDEX idx_ms_identifier (user_identifier), PRIMARY KEY (series)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE member_session');
    }
}
