<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B22b — Stats FFBB individuelles extraites du PDF resume_*.pdf.
 *
 * Table evaluation_ffbb : 1 ligne par (rencontre, joueur), parsée depuis le PDF
 * officiel. Distincte de evaluation_match (saisie manuelle coach) pour permettre
 * un TOGGLE FFBB / Stats coach sur la page match.
 */
final class Version20260612120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B22b : table evaluation_ffbb (stats extraites du PDF resume FFBB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE evaluation_ffbb (
                id INT AUTO_INCREMENT NOT NULL,
                rencontre_id INT NOT NULL,
                joueur_id INT DEFAULT NULL,
                numero_maillot SMALLINT DEFAULT NULL,
                nom_complet VARCHAR(120) NOT NULL,
                est_starter TINYINT(1) DEFAULT 0 NOT NULL,
                minutes_jouees INT DEFAULT 0 NOT NULL,
                points INT DEFAULT 0 NOT NULL,
                tirs_2pt_reussis INT DEFAULT 0 NOT NULL,
                tirs_2pt_tentes INT DEFAULT 0 NOT NULL,
                tirs_3pt_reussis INT DEFAULT 0 NOT NULL,
                tirs_3pt_tentes INT DEFAULT 0 NOT NULL,
                lancers_reussis INT DEFAULT 0 NOT NULL,
                lancers_tentes INT DEFAULT 0 NOT NULL,
                rebonds_off INT DEFAULT 0 NOT NULL,
                rebonds_def INT DEFAULT 0 NOT NULL,
                passes_d INT DEFAULT 0 NOT NULL,
                interceptions INT DEFAULT 0 NOT NULL,
                contres INT DEFAULT 0 NOT NULL,
                fautes_commises INT DEFAULT 0 NOT NULL,
                pertes_balle INT DEFAULT 0 NOT NULL,
                eval_ffbb INT DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_EFFBB_RENCONTRE (rencontre_id),
                INDEX IDX_EFFBB_JOUEUR (joueur_id),
                UNIQUE INDEX UNQ_EFFBB_RENC_NUM (rencontre_id, numero_maillot),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE evaluation_ffbb ADD CONSTRAINT FK_EFFBB_RENCONTRE FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation_ffbb ADD CONSTRAINT FK_EFFBB_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_ffbb DROP FOREIGN KEY FK_EFFBB_RENCONTRE');
        $this->addSql('ALTER TABLE evaluation_ffbb DROP FOREIGN KEY FK_EFFBB_JOUEUR');
        $this->addSql('DROP TABLE evaluation_ffbb');
    }
}
