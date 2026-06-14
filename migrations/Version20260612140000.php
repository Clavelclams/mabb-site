<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B33 — ENT Section Sportive collège César Franck (ouverture sept 2026).
 *
 * - joueur.est_section_sportive : tag boolean — limite accès au module bulletins
 * - joueur.classe_scolaire : "6e A", "5e B", etc. (libre texte)
 * - table bulletin_scolaire : 1 ligne par bulletin uploadé
 * - table note_scolaire : 1 ligne par note (matière, valeur, coefficient, appréciation)
 *
 * Permissions strictes :
 *   - Voir bulletins d'une joueuse : joueuse + parent lié + staff Section Sportive
 *   - Tag posé par staff Section Sportive (pas auto)
 *
 * Préparation API : la structure accepte un JSON externe via BulletinImporter.
 */
final class Version20260612140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B33 ENT : joueur.est_section_sportive + bulletin_scolaire + note_scolaire';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD est_section_sportive TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE joueur ADD classe_scolaire VARCHAR(30) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE bulletin_scolaire (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                annee_scolaire VARCHAR(9) NOT NULL COMMENT 'Ex: 2026-2027',
                trimestre VARCHAR(10) NOT NULL COMMENT 'T1, T2, T3',
                file_path VARCHAR(255) DEFAULT NULL,
                moyenne_generale FLOAT DEFAULT NULL,
                appreciation_globale TEXT DEFAULT NULL,
                uploaded_by_id INT DEFAULT NULL,
                uploaded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                source VARCHAR(20) DEFAULT 'manuel' NOT NULL COMMENT 'manuel ou api_pronote ou api_ecoledirecte',
                INDEX IDX_BS_JOUEUR (joueur_id),
                INDEX IDX_BS_ANNEE (annee_scolaire),
                UNIQUE INDEX UNQ_BS_JOUEUR_ANNEE_TRIM (joueur_id, annee_scolaire, trimestre),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE note_scolaire (
                id INT AUTO_INCREMENT NOT NULL,
                bulletin_id INT NOT NULL,
                matiere VARCHAR(80) NOT NULL,
                moyenne FLOAT DEFAULT NULL,
                coefficient SMALLINT DEFAULT 1 NOT NULL,
                appreciation TEXT DEFAULT NULL,
                moyenne_classe FLOAT DEFAULT NULL,
                moyenne_max_classe FLOAT DEFAULT NULL,
                moyenne_min_classe FLOAT DEFAULT NULL,
                INDEX IDX_NS_BULLETIN (bulletin_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE bulletin_scolaire ADD CONSTRAINT FK_BS_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE bulletin_scolaire ADD CONSTRAINT FK_BS_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE note_scolaire ADD CONSTRAINT FK_NS_BULLETIN FOREIGN KEY (bulletin_id) REFERENCES bulletin_scolaire (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE note_scolaire DROP FOREIGN KEY FK_NS_BULLETIN');
        $this->addSql('ALTER TABLE bulletin_scolaire DROP FOREIGN KEY FK_BS_JOUEUR');
        $this->addSql('ALTER TABLE bulletin_scolaire DROP FOREIGN KEY FK_BS_UPLOADED_BY');
        $this->addSql('DROP TABLE note_scolaire');
        $this->addSql('DROP TABLE bulletin_scolaire');
        $this->addSql('ALTER TABLE joueur DROP est_section_sportive');
        $this->addSql('ALTER TABLE joueur DROP classe_scolaire');
    }
}
