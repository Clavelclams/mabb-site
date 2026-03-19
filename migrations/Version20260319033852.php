<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260319033852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE page_contenu (id INT AUTO_INCREMENT NOT NULL, page_slug VARCHAR(100) NOT NULL, page_nom VARCHAR(255) NOT NULL, contenu LONGTEXT DEFAULT NULL, sous_titre VARCHAR(255) DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_73A2EBAA1F5987B8 (page_slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE page_contenu');
    }
}
