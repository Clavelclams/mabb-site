<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [CMS V2 — 05/07/2026] Table bloc_contenu : contenus vitrine éditables
 * au niveau BLOC (titres, paragraphes, images, chiffres) depuis
 * /admin/contenus. Les clés s'auto-enregistrent au premier rendu des
 * templates via la fonction Twig cms() — aucune fixture requise.
 */
final class Version20260705120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CMS V2 — table bloc_contenu (contenus vitrine éditables par bloc)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bloc_contenu (
            id INT AUTO_INCREMENT NOT NULL,
            cle VARCHAR(150) NOT NULL,
            page VARCHAR(50) NOT NULL,
            type VARCHAR(10) NOT NULL,
            valeur LONGTEXT DEFAULT NULL,
            defaut LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_bloc_contenu_cle (cle),
            INDEX idx_bloc_contenu_page (page),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bloc_contenu');
    }
}
