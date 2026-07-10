<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [Engagement V1, 10/07/2026] Table pirb_seance_playground — les séances
 * des jeux auto (tir/dribble) remontées par l'app, base du classement club.
 *
 * Migration écrite à la main (uniquement cette table, zéro drift).
 * Index (joueur, created_at) : historique d'une joueuse.
 * Index (mode, created_at) : le classement filtre par mode + fenêtre 7 jours.
 */
final class Version20260710120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engagement V1 : table pirb_seance_playground (séances jeux auto + classement)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE pirb_seance_playground (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                mode VARCHAR(10) NOT NULL,
                reussis INT NOT NULL,
                rates INT NOT NULL,
                score INT NOT NULL,
                duree_secondes INT NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_psp_joueur_date (joueur_id, created_at),
                INDEX idx_psp_mode_date (mode, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );
        $this->addSql('ALTER TABLE pirb_seance_playground ADD CONSTRAINT FK_psp_joueur FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pirb_seance_playground');
    }
}
