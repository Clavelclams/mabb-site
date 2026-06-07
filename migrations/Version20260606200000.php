<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase F — upload fichiers + synthèse publique + suivi PV.
 *
 * 3 changements en 1 migration :
 *   1. Ajout colonnes reunion.synthese_publique (text) + synthese_publiee (bool)
 *   2. Création table reunion_document (fichiers attachés)
 *   3. Création table reunion_pv_version (snapshots historique du PV)
 *
 * MULTI-TENANT : pas de colonne club_id dupliquée — l'isolation passe par
 * reunion → club_id, vérifié par ClubVoter.
 */
final class Version20260606200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau Manager Phase F — fichiers + synthèse publique + suivi PV';
    }

    public function up(Schema $schema): void
    {
        // === 1. Ajout des colonnes synthèse sur reunion ===
        $this->addSql(<<<SQL
            ALTER TABLE reunion
                ADD synthese_publique LONGTEXT DEFAULT NULL,
                ADD synthese_publiee TINYINT(1) NOT NULL DEFAULT 0
        SQL);

        // === 2. Table reunion_document (fichiers attachés) ===
        $this->addSql(<<<SQL
            CREATE TABLE reunion_document (
                id INT AUTO_INCREMENT NOT NULL,
                reunion_id INT NOT NULL,
                uploade_par_id INT DEFAULT NULL,
                nom_original VARCHAR(255) NOT NULL,
                path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                taille INT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_rd_reunion (reunion_id),
                INDEX IDX_RD_UPLOADER (uploade_par_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE reunion_document ADD CONSTRAINT FK_RD_REUNION FOREIGN KEY (reunion_id) REFERENCES reunion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reunion_document ADD CONSTRAINT FK_RD_UPLOADER FOREIGN KEY (uploade_par_id) REFERENCES user (id) ON DELETE SET NULL');

        // === 3. Table reunion_pv_version (snapshots PV) ===
        $this->addSql(<<<SQL
            CREATE TABLE reunion_pv_version (
                id INT AUTO_INCREMENT NOT NULL,
                reunion_id INT NOT NULL,
                modifie_par_id INT DEFAULT NULL,
                contenu_snapshot LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_rpvv_reunion_date (reunion_id, created_at),
                INDEX IDX_RPVV_AUTHOR (modifie_par_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE reunion_pv_version ADD CONSTRAINT FK_RPVV_REUNION FOREIGN KEY (reunion_id) REFERENCES reunion (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reunion_pv_version ADD CONSTRAINT FK_RPVV_AUTHOR FOREIGN KEY (modifie_par_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE reunion_pv_version');
        $this->addSql('DROP TABLE reunion_document');
        $this->addSql('ALTER TABLE reunion DROP synthese_publique, DROP synthese_publiee');
    }
}
