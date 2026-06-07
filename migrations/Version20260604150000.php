<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Éval Match — Étape 1 : création de la table evaluation_match.
 *
 * Une éval = un joueur × une rencontre (unicité forte BDD via UNIQUE INDEX).
 *
 * Tous les compteurs sont en SMALLINT (-32768 à 32767) car aucune stat
 * basket ne dépassera jamais 200 sur un match. Économie d'espace vs INT.
 *
 * Pas de colonne club_id : le multi-tenant passe par $joueur->getClub()
 * (méthode getClub() de l'entité, déléguée via ClubAwareInterface).
 */
final class Version20260604150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Éval Match — création table evaluation_match (Étape 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE evaluation_match (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                rencontre_id INT NOT NULL,
                is_starter TINYINT(1) NOT NULL,
                minutes_jouees SMALLINT NOT NULL,
                tirs2pts_reussis SMALLINT NOT NULL,
                tirs2pts_tentes SMALLINT NOT NULL,
                tirs3pts_reussis SMALLINT NOT NULL,
                tirs3pts_tentes SMALLINT NOT NULL,
                lancers_reussis SMALLINT NOT NULL,
                lancers_tentes SMALLINT NOT NULL,
                rebonds_offensifs SMALLINT NOT NULL,
                rebonds_defensifs SMALLINT NOT NULL,
                passes_decisives SMALLINT NOT NULL,
                interceptions SMALLINT NOT NULL,
                contres SMALLINT NOT NULL,
                contres_subis SMALLINT NOT NULL,
                fautes_commises SMALLINT NOT NULL,
                fautes_provoquees SMALLINT NOT NULL,
                pertes_balle SMALLINT NOT NULL,
                notes_coach LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX unique_joueur_rencontre (joueur_id, rencontre_id),
                INDEX IDX_EM_JOUEUR (joueur_id),
                INDEX IDX_EM_RENCONTRE (rencontre_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        // FK avec ON DELETE CASCADE :
        //   - Si le joueur est supprimé → ses évals partent aussi (pas d'orphelins)
        //   - Si la rencontre est supprimée → idem
        $this->addSql(<<<SQL
            ALTER TABLE evaluation_match
                ADD CONSTRAINT FK_EM_JOUEUR
                FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<SQL
            ALTER TABLE evaluation_match
                ADD CONSTRAINT FK_EM_RENCONTRE
                FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE evaluation_match');
    }
}
