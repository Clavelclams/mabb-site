<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B22c — Tirs réussis FFBB extraits du PDF positiontir_*.pdf.
 *
 * Table tir_ffbb : 1 ligne par tir marqué (extrait du PDF).
 * Position X/Y en pourcentages 0-100 (compat ShotChartCalculator existant).
 *
 * NOTE : la version V1 du parser extrait juste les noms et compte les tirs.
 * L'extraction visuelle des coordonnées des "X" sur les mini-terrains demande
 * une lib graphique (sharp/imagick/tesseract) qui sera ajoutée en V2 si nécessaire.
 *
 * En V1 on stocke les tirs avec position NULL et un nombre total par joueuse.
 * Ça permet déjà d'afficher "X tirs réussis FFBB" sur la page match.
 */
final class Version20260612130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B22c : table tir_ffbb (positions de tirs FFBB — V1 sans coordonnées)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tir_ffbb (
                id INT AUTO_INCREMENT NOT NULL,
                rencontre_id INT NOT NULL,
                joueur_id INT DEFAULT NULL,
                nom_joueuse VARCHAR(120) NOT NULL,
                position_x SMALLINT DEFAULT NULL COMMENT 'X 0-100 % du terrain',
                position_y SMALLINT DEFAULT NULL COMMENT 'Y 0-100 % du terrain',
                type_tir VARCHAR(20) DEFAULT NULL COMMENT '2pt_int, 2pt_ext, 3pt',
                est_reussi TINYINT(1) DEFAULT 1 NOT NULL,
                source VARCHAR(20) DEFAULT 'ffbb' NOT NULL COMMENT 'ffbb ou stats_live',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_TIRFFBB_RENCONTRE (rencontre_id),
                INDEX IDX_TIRFFBB_JOUEUR (joueur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE tir_ffbb ADD CONSTRAINT FK_TIRFFBB_RENCONTRE FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tir_ffbb ADD CONSTRAINT FK_TIRFFBB_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tir_ffbb DROP FOREIGN KEY FK_TIRFFBB_RENCONTRE');
        $this->addSql('ALTER TABLE tir_ffbb DROP FOREIGN KEY FK_TIRFFBB_JOUEUR');
        $this->addSql('DROP TABLE tir_ffbb');
    }
}
