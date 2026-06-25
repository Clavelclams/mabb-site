<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B22a-sec — Sécurité PDFs FFBB : table demande_acces_pdf.
 *
 * Contexte métier :
 *   Willy (dirigeant MABB) ne veut pas que les joueuses téléchargent
 *   directement les PDFs officiels FFBB (feuille de match, résumé stats,
 *   positions de tirs). Ces docs contiennent les données de TOUTES les
 *   joueuses de l'équipe.
 *
 *   Solution : workflow demande → validation coach.
 *   La joueuse clique "Demander l'accès", le coach approuve dans Manager,
 *   la joueuse peut ensuite télécharger.
 *
 * Structure :
 *   - joueur_id + rencontre_id + type_pdf = clé fonctionnelle unique
 *   - statut : pending / approved / rejected
 *   - coach_id : qui a décidé (NULL tant que pas décidé)
 *   - decided_at : timestamp de la décision
 *   - message_coach : retour optionnel du coach
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B22a-sec — Table demande_acces_pdf : workflow accès PDFs FFBB joueuse → validation coach.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE demande_acces_pdf (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                rencontre_id INT NOT NULL,
                coach_id INT DEFAULT NULL,
                type_pdf VARCHAR(20) NOT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT \'pending\',
                message_coach LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_DAP_JOUEUR (joueur_id),
                INDEX IDX_DAP_RENCONTRE (rencontre_id),
                INDEX IDX_DAP_COACH (coach_id),
                INDEX IDX_DAP_STATUT (statut),
                UNIQUE INDEX UNQ_DAP_JOUEUR_REN_TYPE (joueur_id, rencontre_id, type_pdf),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            ALTER TABLE demande_acces_pdf
            ADD CONSTRAINT FK_DAP_JOUEUR
            FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        ');

        $this->addSql('
            ALTER TABLE demande_acces_pdf
            ADD CONSTRAINT FK_DAP_RENCONTRE
            FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE
        ');

        $this->addSql('
            ALTER TABLE demande_acces_pdf
            ADD CONSTRAINT FK_DAP_COACH
            FOREIGN KEY (coach_id) REFERENCES user (id) ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_acces_pdf DROP FOREIGN KEY FK_DAP_JOUEUR');
        $this->addSql('ALTER TABLE demande_acces_pdf DROP FOREIGN KEY FK_DAP_RENCONTRE');
        $this->addSql('ALTER TABLE demande_acces_pdf DROP FOREIGN KEY FK_DAP_COACH');
        $this->addSql('DROP TABLE demande_acces_pdf');
    }
}
