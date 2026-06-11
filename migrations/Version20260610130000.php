<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B5 — Affectation Coach↔Équipe.
 * Table de jointure : un User (rôle COACH) peut coacher N équipes
 * et une équipe peut avoir N coachs (principal + assistants).
 *
 * Débloque le palier "Mon coach" de la visibilité 5 paliers (B13).
 */
final class Version20260610130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B5 sportif : table coach_equipe (jointure N-N saison)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE coach_equipe (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                equipe_id INT NOT NULL,
                role_coach VARCHAR(30) NOT NULL COMMENT 'PRINCIPAL, ASSISTANT',
                saison VARCHAR(20) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_CE_USER (user_id),
                INDEX IDX_CE_EQUIPE (equipe_id),
                UNIQUE INDEX UNQ_CE_USER_EQUIPE_SAISON (user_id, equipe_id, saison),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql('ALTER TABLE coach_equipe ADD CONSTRAINT FK_CE_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coach_equipe ADD CONSTRAINT FK_CE_EQUIPE FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach_equipe DROP FOREIGN KEY FK_CE_USER');
        $this->addSql('ALTER TABLE coach_equipe DROP FOREIGN KEY FK_CE_EQUIPE');
        $this->addSql('DROP TABLE coach_equipe');
    }
}
