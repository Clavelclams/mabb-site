<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase D.1 — Fondations trésorerie.
 *
 * Crée la table `operation_tresorerie` qui stocke les opérations financières
 * d'un club (recettes / dépenses) avec catégorisation plan comptable
 * simplifié + justificatif optionnel.
 *
 * MULTI-TENANT :
 *   - club_id FK NOT NULL → CASCADE si club supprimé.
 *   - Toute requête doit filtrer par club_id côté repo.
 *
 * INDEX :
 *   - (club_id, date) → l'usage principal est "lister les opérations d'un
 *     club, plus récentes d'abord". L'index composite couvre ce cas.
 *   - (club_id, type) → pour les sumByType().
 *   - (club_id, categorie) → pour les agrégations par catégorie.
 *
 * CONTRAINTES MÉTIER NON GARANTIES PAR LA BDD :
 *   - Cohérence type ↔ catégorie (RECETTE = COTISATIONS, DEPENSE = EQUIPEMENTS...) :
 *     vérifiée côté PHP (isCategorieValidePourType()). Pour l'imposer en BDD
 *     il faudrait des CHECK constraints — Doctrine ne gère pas bien et c'est
 *     overkill.
 *   - Montant > 0 : vérifié côté PHP. Idem.
 *
 * Pour Phase D.2 : ajout d'un champ note_frais_id FK (relation 1:1 inversée)
 * pour relier une opération générée auto par validation d'une note de frais.
 */
final class Version20260607100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau D.1 — Trésorerie : table operation_tresorerie (recettes/dépenses + catégories + justificatif)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE operation_tresorerie (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                type VARCHAR(16) NOT NULL,
                categorie VARCHAR(32) NOT NULL,
                montant NUMERIC(10, 2) NOT NULL,
                date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                libelle VARCHAR(255) NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                justificatif_path VARCHAR(255) DEFAULT NULL,
                justificatif_nom_original VARCHAR(255) DEFAULT NULL,
                justificatif_mime_type VARCHAR(100) DEFAULT NULL,
                justificatif_taille INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_OP_TRESO_CLUB (club_id),
                INDEX IDX_OP_TRESO_CREATED_BY (created_by_id),
                INDEX IDX_OP_TRESO_CLUB_DATE (club_id, date),
                INDEX IDX_OP_TRESO_CLUB_TYPE (club_id, type),
                INDEX IDX_OP_TRESO_CLUB_CAT (club_id, categorie),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE operation_tresorerie
            ADD CONSTRAINT FK_OP_TRESO_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE operation_tresorerie
            ADD CONSTRAINT FK_OP_TRESO_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE operation_tresorerie DROP FOREIGN KEY FK_OP_TRESO_CLUB');
        $this->addSql('ALTER TABLE operation_tresorerie DROP FOREIGN KEY FK_OP_TRESO_CREATED_BY');
        $this->addSql('DROP TABLE operation_tresorerie');
    }
}
