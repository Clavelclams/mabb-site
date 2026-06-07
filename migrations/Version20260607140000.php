<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase D.3.1 — Tarifs cotisation par catégorie d'âge.
 *
 * Crée la table `tarif_cotisation` :
 *   - 1 ligne par (club, catégorie, saison)
 *   - Contrainte UNIQUE composite pour empêcher les doublons
 *
 * USAGE :
 *   Le trésorier saisit les tarifs sur /tresorerie/tarifs.
 *   Lors de la génération de cotisations, le CotisationGenerator récupère
 *   le tarif correspondant à la catégorie de l'équipe de chaque joueuse.
 *   Fallback sur le montant par défaut si pas de tarif défini.
 */
final class Version20260607140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau D.3.1 — Tarifs cotisation par catégorie d\'âge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE tarif_cotisation (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                categorie VARCHAR(32) NOT NULL,
                saison VARCHAR(9) NOT NULL,
                montant NUMERIC(10, 2) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_TARIF_CLUB (club_id),
                UNIQUE INDEX UNIQ_TARIF_CLUB_CAT_SAISON (club_id, categorie, saison),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE tarif_cotisation
            ADD CONSTRAINT FK_TARIF_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarif_cotisation DROP FOREIGN KEY FK_TARIF_CLUB');
        $this->addSql('DROP TABLE tarif_cotisation');
    }
}
