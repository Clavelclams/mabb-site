<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase D.4 — Subventions.
 *
 * Crée la table `subvention` qui track le cycle de vie d'une demande
 * de subvention : EN_PREPARATION → DEPOSEE → ACCORDEE → TOUCHEE/REJETEE.
 *
 * INDEX :
 *   - (club_id, saison) : pour la liste filtrée par saison
 *   - (club_id, statut) : pour les compteurs/agrégations dashboard
 *
 * PAS DE FK sur operation_tresorerie_id : on garde un simple INT nullable
 * pour éviter de surcharger l'entité OperationTresorerie avec encore une
 * relation 1:1 (déjà couplée à noteFrais). La traçabilité passe par le
 * champ "notes" textuel de l'opération.
 */
final class Version20260607150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau D.4 — Subventions : table subvention avec workflow 5 statuts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE subvention (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                organisme VARCHAR(150) NOT NULL,
                intitule VARCHAR(255) NOT NULL,
                reference_dossier VARCHAR(100) DEFAULT NULL,
                lien_dossier VARCHAR(500) DEFAULT NULL,
                montant_demande NUMERIC(10, 2) NOT NULL,
                montant_accorde NUMERIC(10, 2) DEFAULT NULL,
                montant_touche NUMERIC(10, 2) DEFAULT NULL,
                statut VARCHAR(16) NOT NULL,
                saison VARCHAR(9) NOT NULL,
                date_depot DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                date_decision DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                date_touche DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)',
                motif_rejet LONGTEXT DEFAULT NULL,
                notes LONGTEXT DEFAULT NULL,
                operation_tresorerie_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_SUB_CLUB (club_id),
                INDEX IDX_SUB_CREATED_BY (created_by_id),
                INDEX IDX_SUB_CLUB_SAISON (club_id, saison),
                INDEX IDX_SUB_CLUB_STATUT (club_id, statut),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE subvention
            ADD CONSTRAINT FK_SUB_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<SQL
            ALTER TABLE subvention
            ADD CONSTRAINT FK_SUB_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subvention DROP FOREIGN KEY FK_SUB_CLUB');
        $this->addSql('ALTER TABLE subvention DROP FOREIGN KEY FK_SUB_CREATED_BY');
        $this->addSql('DROP TABLE subvention');
    }
}
