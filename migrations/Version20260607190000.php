<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats Live V2.1d — Sessions multi-auteurs.
 *
 * Crée la table session_stats_live et ajoute session_id NULLABLE sur
 * action_match + presence_terrain.
 *
 * NULLABLE volontairement : les données créées AVANT V2.1d n'ont pas de
 * session — on ne touche pas. Toute nouvelle action sera rattachée.
 *
 * INDEX :
 *   - (rencontre_id, statut) → trouver l'OFFICIELLE rapidement
 *   - (created_by_id, statut) → compter les sessions officielles par user
 *     pour la future gamification PIRB (badges "Pro des Stats").
 */
final class Version20260607190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats Live V2.1d — session_stats_live + session_id sur action_match/presence_terrain';
    }

    public function up(Schema $schema): void
    {
        // === Table session_stats_live ===
        $this->addSql(<<<SQL
            CREATE TABLE session_stats_live (
                id INT AUTO_INCREMENT NOT NULL,
                rencontre_id INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                promoted_by_id INT DEFAULT NULL,
                nom VARCHAR(100) NOT NULL,
                statut VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                promoted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_SS_RENCONTRE (rencontre_id),
                INDEX IDX_SS_CREATED_BY (created_by_id),
                INDEX IDX_SS_PROMOTED_BY (promoted_by_id),
                INDEX IDX_SS_RENCONTRE_STATUT (rencontre_id, statut),
                INDEX IDX_SS_CREATEDBY_STATUT (created_by_id, statut),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE session_stats_live ADD CONSTRAINT FK_SS_RENCONTRE FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE session_stats_live ADD CONSTRAINT FK_SS_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE session_stats_live ADD CONSTRAINT FK_SS_PROMOTED_BY FOREIGN KEY (promoted_by_id) REFERENCES user (id) ON DELETE SET NULL');

        // === FK session_id sur action_match ===
        $this->addSql('ALTER TABLE action_match ADD session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE action_match ADD CONSTRAINT FK_AM_SESSION FOREIGN KEY (session_id) REFERENCES session_stats_live (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_AM_SESSION ON action_match (session_id)');

        // === FK session_id sur presence_terrain ===
        $this->addSql('ALTER TABLE presence_terrain ADD session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE presence_terrain ADD CONSTRAINT FK_PT_SESSION FOREIGN KEY (session_id) REFERENCES session_stats_live (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_PT_SESSION ON presence_terrain (session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE presence_terrain DROP FOREIGN KEY FK_PT_SESSION');
        $this->addSql('DROP INDEX IDX_PT_SESSION ON presence_terrain');
        $this->addSql('ALTER TABLE presence_terrain DROP session_id');

        $this->addSql('ALTER TABLE action_match DROP FOREIGN KEY FK_AM_SESSION');
        $this->addSql('DROP INDEX IDX_AM_SESSION ON action_match');
        $this->addSql('ALTER TABLE action_match DROP session_id');

        $this->addSql('ALTER TABLE session_stats_live DROP FOREIGN KEY FK_SS_RENCONTRE');
        $this->addSql('ALTER TABLE session_stats_live DROP FOREIGN KEY FK_SS_CREATED_BY');
        $this->addSql('ALTER TABLE session_stats_live DROP FOREIGN KEY FK_SS_PROMOTED_BY');
        $this->addSql('DROP TABLE session_stats_live');
    }
}
