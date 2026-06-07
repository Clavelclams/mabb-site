<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats Live — Phase 1A : création de la table action_match.
 *
 * Une action = un événement atomique pendant un match.
 * Granularité fine pour reconstruire l'historique complet (shot chart, momentum)
 * et alimenter le service d'agrégation vers EvaluationMatch.
 *
 * INDEX :
 *   - idx_am_rencontre_joueur : queries par match × joueuse (agrégation)
 *   - idx_am_rencontre_type   : queries par type d'action sur un match (ex: tous les tirs)
 *
 * FK avec ON DELETE CASCADE : si on supprime un joueur ou une rencontre, ses
 * actions disparaissent aussi. Le SET NULL sur assistJoueur permet de garder
 * l'action de tir même si la joueuse qui a fait l'assist est supprimée.
 *
 * VARCHAR(30) pour type : largement suffisant ("tir_2pt_int_reussi" = 19 chars).
 * VARCHAR(5) pour quart-temps : "EXT1" max = 4 chars + marge.
 * SMALLINT pour minute/secondes : valeurs max 15/59.
 * FLOAT pour positionX/Y : précision suffisante pour shot chart pixel-perfect.
 */
final class Version20260605120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats Live Phase 1A — création table action_match (entité granulaire actions match)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE action_match (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                rencontre_id INT NOT NULL,
                assist_joueur_id INT DEFAULT NULL,
                type VARCHAR(30) NOT NULL,
                quart_temps VARCHAR(5) NOT NULL,
                minute SMALLINT NOT NULL,
                secondes SMALLINT NOT NULL,
                position_x DOUBLE PRECISION DEFAULT NULL,
                position_y DOUBLE PRECISION DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_am_rencontre_joueur (rencontre_id, joueur_id),
                INDEX idx_am_rencontre_type (rencontre_id, type),
                INDEX IDX_ACTION_ASSIST (assist_joueur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE action_match
                ADD CONSTRAINT FK_AM_JOUEUR
                FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE action_match
                ADD CONSTRAINT FK_AM_RENCONTRE
                FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE action_match
                ADD CONSTRAINT FK_AM_ASSIST
                FOREIGN KEY (assist_joueur_id) REFERENCES joueur (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE action_match');
    }
}
