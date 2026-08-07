<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Order independence, part 1 — an order stops dying with its form, and starts carrying its own facts.
 *
 * TWO changes, and the ORDER of the statements matters:
 *
 *  1. `fb_order.form_id` becomes NULLABLE and its foreign key flips from ON DELETE CASCADE to
 *     ON DELETE SET NULL. Until now the database silently deleted every order placed through a form
 *     whenever that form was deleted — the sales record, gone, with no PHP event and no trace.
 *     FormDeletionGuard already blocks the admin delete action, but it deliberately does not cover the
 *     demo uninstaller or a direct em->remove(); this is the database-level belt behind that suspender.
 *
 *  2. Three snapshot columns record, at purchase time, what the order used to read live off the form:
 *     the product name, the buyer's submitted data (ship_* keys included), and whether the sale went
 *     through a Merchant-of-Record form. Nothing READS them yet — the existing read sites still go
 *     through the live form, so this migration changes no visible behaviour.
 *
 * BACKFILL runs here, inside the migration, not as a separate command — a site owner must never have to
 * run anything by hand for their existing orders to keep their product name. It is SET-BASED (one UPDATE
 * ... JOIN per column group), so it costs the same shape of work on 13 rows or on 130 000; it never loads
 * rows into PHP. Both statements are guarded by `IS NULL` so a re-run is a no-op.
 *
 * The backfill deliberately runs AFTER the schema change but while every FK still points at a live row —
 * nothing is deleted here, so `INNER JOIN` reaches every order that currently has a form / submission.
 * An order whose submission was already gone (submission_id was SET NULL long ago) gets no data snapshot;
 * that data is not recoverable and inventing an empty array would claim a snapshot that was never taken.
 */
final class Version20260807081500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order independence: fb_order.form_id nullable + SET NULL, and product/customer/MoR snapshots (backfilled)';
    }

    public function up(Schema $schema): void
    {
        // 1) Snapshot columns. All additive; is_merchant_of_record defaults to false so an ordinary
        //    self-billed order needs no backfill of its own.
        $this->addSql(<<<'SQL'
            ALTER TABLE fb_order
                ADD product_name VARCHAR(255) DEFAULT NULL,
                ADD submission_data JSON DEFAULT NULL,
                ADD is_merchant_of_record TINYINT(1) DEFAULT 0 NOT NULL
            SQL);

        // 2) The form FK stops being lethal. Drop → widen to nullable → re-add with SET NULL.
        //    (The FK must go first: MySQL will not let the referencing column's nullability change
        //    underneath a live constraint whose delete rule also changes.)
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E5FF69B7D');
        $this->addSql('ALTER TABLE fb_order CHANGE form_id form_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E5FF69B7D FOREIGN KEY (form_id) REFERENCES fb_form (id) ON DELETE SET NULL');

        // 3) Backfill the product name + the MoR flag from the form still on the order. Written together
        //    in one statement (they come from the same row) and guarded so a re-run does nothing.
        $this->addSql(<<<'SQL'
            UPDATE fb_order o
            INNER JOIN fb_form f ON f.id = o.form_id
            SET o.product_name = f.name,
                o.is_merchant_of_record = (f.form_type = 'digital_mor')
            WHERE o.product_name IS NULL
            SQL);

        // 4) Backfill the buyer's data from the submission still on the order. Column-to-column copy of a
        //    native JSON value — no re-encoding, so the stored bytes stay exactly what the buyer submitted.
        $this->addSql(<<<'SQL'
            UPDATE fb_order o
            INNER JOIN fb_submission s ON s.id = o.submission_id
            SET o.submission_data = s.data
            WHERE o.submission_data IS NULL
            SQL);
    }

    /**
     * Refuse to go back while orphaned orders exist, instead of destroying them.
     *
     * down() restores `form_id NOT NULL` + ON DELETE CASCADE. If any order has already lost its form
     * (form_id IS NULL — exactly the situation this migration exists to survive), that restore is
     * impossible without deleting those orders, and quietly deleting paid orders to satisfy a rollback
     * is never the right call. So we stop and say so; the operator decides what those orders should be
     * attached to. MySQL would fail on the ALTER anyway — this just fails with an explanation.
     */
    public function preDown(Schema $schema): void
    {
        $orphans = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fb_order WHERE form_id IS NULL');

        $this->abortIf(
            $orphans > 0,
            \sprintf(
                'Cannot roll back: %d order(s) no longer have a form (form_id IS NULL). Restoring the NOT NULL '
                .'constraint would require deleting them. Re-attach or export those orders first.',
                $orphans
            )
        );
    }

    public function down(Schema $schema): void
    {
        // Back to the lethal CASCADE. Dropping the columns takes the snapshots with them, which is the
        // correct reversal — there is nowhere else to put them.
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E5FF69B7D');
        $this->addSql('ALTER TABLE fb_order CHANGE form_id form_id INT NOT NULL');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E5FF69B7D FOREIGN KEY (form_id) REFERENCES fb_form (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE fb_order DROP product_name, DROP submission_data, DROP is_merchant_of_record');
    }
}
