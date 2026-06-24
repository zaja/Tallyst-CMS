<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624111959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE FULLTEXT INDEX ft_category_name ON category (name)');
        $this->addSql('CREATE FULLTEXT INDEX ft_category_description ON category (description)');
        $this->addSql('CREATE FULLTEXT INDEX ft_page_title ON page (title)');
        $this->addSql('CREATE FULLTEXT INDEX ft_page_content ON page (content)');
        $this->addSql('CREATE FULLTEXT INDEX ft_post_title ON post (title)');
        $this->addSql('CREATE FULLTEXT INDEX ft_post_body ON post (excerpt, content)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX ft_category_name ON category');
        $this->addSql('DROP INDEX ft_category_description ON category');
        $this->addSql('DROP INDEX ft_page_title ON page');
        $this->addSql('DROP INDEX ft_page_content ON page');
        $this->addSql('DROP INDEX ft_post_title ON post');
        $this->addSql('DROP INDEX ft_post_body ON post');
    }
}
