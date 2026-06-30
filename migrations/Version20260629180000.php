<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table affectation_match — gestion des rôles staff/bénévole par rencontre.
 *
 * Rôles : DELEGUE, CHRONO, EMARQUE, ARBITRE_1, ARBITRE_2, BUVETTE, OPERATEUR, STATS_LIVE, RESPONSABLE_SALLE
 * Statuts : ASSIGNE (admin direct), CANDIDAT (bénévole en attente), CONFIRME, ABSENT
 */
final class Version20260629180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Affectation staff/bénévole aux rôles par rencontre';
    }

    public function up(Schema $schema): void
    {
        // Nettoyage idempotent : si la table existait d'une tentative précédente,
        // on la supprime proprement avant de la recréer.
        $this->addSql('DROP TABLE IF EXISTS affectation_match');

        $this->addSql('
            CREATE TABLE affectation_match (
                id           INT AUTO_INCREMENT NOT NULL,
                rencontre_id INT NOT NULL,
                user_id      INT DEFAULT NULL,
                role         VARCHAR(30) NOT NULL,
                statut       VARCHAR(20) NOT NULL DEFAULT \'ASSIGNE\',
                note         LONGTEXT DEFAULT NULL,
                created_at   DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at   DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_affmatch_rencontre (rencontre_id),
                INDEX IDX_affmatch_user (user_id),
                INDEX IDX_affmatch_statut (statut),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Noms de FK longs et uniques pour éviter les collisions globales MySQL
        $this->addSql('
            ALTER TABLE affectation_match
                ADD CONSTRAINT FK_affmatch_rencontre_id FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_affmatch_user_id FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE affectation_match DROP FOREIGN KEY FK_affmatch_rencontre_id');
        $this->addSql('ALTER TABLE affectation_match DROP FOREIGN KEY FK_affmatch_user_id');
        $this->addSql('DROP TABLE affectation_match');
    }
}
