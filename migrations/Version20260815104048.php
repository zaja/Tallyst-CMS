<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records which page a checkout started from, so an unfinished one can be offered back to the buyer.
 *
 * A form has no URL of its own in Tallyst — it lives embedded in a page through `[form id=N]` — and
 * nothing recorded WHICH page. Without this column the only thing an abandoned-checkout e-mail could
 * say is "go to the home page and find it again", which is not an offer to finish a purchase.
 *
 * Existing orders keep NULL and are simply never offered a retry; nothing is backfilled, because the
 * page a months-old checkout came from is not recoverable and guessing it would send buyers to the
 * wrong product.
 */
final class Version20260815104048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Orders: remember which page a checkout started from, so an unfinished one can be resumed';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_order ADD return_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_order DROP return_path');
    }
}
