<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * V1.6 — Surclassement FFBB : table joueur_equipe (Many-to-Many enrichi
 * entre Joueur et Equipe).
 *
 * Voir src/Entity/Sport/JoueurEquipe.php pour la doc métier.
 *
 * INVARIANT POST-MIGRATION :
 *   Pour chaque Joueur avec equipe_id NOT NULL au moment de la migration,
 *   il existe exactement 1 JoueurEquipe (type='principale', saison=équipe.saison).
 *   Les surclassements (type='surclassement') sont ajoutés manuellement
 *   par le Manager après cette migration.
 */
final class Version20260615234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V1.6 — Surclassement FFBB : creation table joueur_equipe + data migration des affectations principales existantes.';
    }

    public function up(Schema $schema): void
    {
        // === 1. Création de la table joueur_equipe ===
        $this->addSql('
            CREATE TABLE joueur_equipe (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                equipe_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                saison VARCHAR(9) NOT NULL,
                actif TINYINT(1) NOT NULL,
                notes LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_JE_JOUEUR (joueur_id),
                INDEX IDX_JE_EQUIPE (equipe_id),
                INDEX idx_joueur_equipe_saison_actif (saison, actif),
                UNIQUE INDEX uniq_joueur_equipe_saison (joueur_id, equipe_id, saison),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // === 2. Foreign keys (ON DELETE CASCADE pour cohérence avec onDelete dans l'entité) ===
        $this->addSql('
            ALTER TABLE joueur_equipe
            ADD CONSTRAINT FK_JE_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE
        ');
        $this->addSql('
            ALTER TABLE joueur_equipe
            ADD CONSTRAINT FK_JE_EQUIPE FOREIGN KEY (equipe_id) REFERENCES equipe (id) ON DELETE CASCADE
        ');

        // === 3. Data migration : pour chaque joueur affecté à une équipe, créer son affectation
        //        principale en se basant sur la saison de l'équipe (sécurise la cohérence) ===
        $this->addSql('
            INSERT INTO joueur_equipe (joueur_id, equipe_id, type, saison, actif, created_at)
            SELECT j.id, j.equipe_id, \'principale\', e.saison, 1, NOW()
            FROM joueur j
            INNER JOIN equipe e ON e.id = j.equipe_id
            WHERE j.equipe_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        // Rollback propre : on retire les FK avant de drop la table
        $this->addSql('ALTER TABLE joueur_equipe DROP FOREIGN KEY FK_JE_JOUEUR');
        $this->addSql('ALTER TABLE joueur_equipe DROP FOREIGN KEY FK_JE_EQUIPE');
        $this->addSql('DROP TABLE joueur_equipe');
    }
}
