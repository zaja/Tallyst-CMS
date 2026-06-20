<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620213521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fb_order (id INT AUTO_INCREMENT NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, provider VARCHAR(20) NOT NULL, provider_session_id VARCHAR(255) DEFAULT NULL, provider_payment_intent_id VARCHAR(255) DEFAULT NULL, customer_email VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, form_id INT NOT NULL, submission_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_A15CF53E4489A067 (provider_session_id), INDEX IDX_A15CF53E5FF69B7D (form_id), INDEX IDX_A15CF53EE1FD4933 (submission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53E5FF69B7D FOREIGN KEY (form_id) REFERENCES fb_form (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fb_order ADD CONSTRAINT FK_A15CF53EE1FD4933 FOREIGN KEY (submission_id) REFERENCES fb_submission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fb_form ADD price_minor INT DEFAULT NULL, DROP price');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53E5FF69B7D');
        $this->addSql('ALTER TABLE fb_order DROP FOREIGN KEY FK_A15CF53EE1FD4933');
        $this->addSql('DROP TABLE fb_order');
        $this->addSql('ALTER TABLE fb_form ADD price NUMERIC(10, 2) DEFAULT NULL, DROP price_minor');
    }
}
