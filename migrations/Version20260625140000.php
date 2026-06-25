<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B-Seances V1 (Part 2/2) — Entités pédagogiques + modifications Seance.
 *
 * Tables créées :
 *   - contenu_seance         : fiche pédagogique (titre, description, catégories, fichiers)
 *   - contenu_seance_theme   : jonction ContenuSeance ↔ ThemeSeance (M:N)
 *   - note_seance            : feedback anonyme joueur sur une séance
 *   - seance_solo            : entraînement individuel déclaré par la joueuse
 *
 * Tables modifiées :
 *   - seance : + intitule (varchar nullable) + contenu_prive (bool) + contenu_seance_id (FK nullable)
 */
final class Version20260625140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B-Seances — contenu_seance + note_seance + seance_solo + ALTER seance.';
    }

    public function up(Schema $schema): void
    {
        // ─── 1. contenu_seance ─────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE contenu_seance (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT NOT NULL,
                created_by_id INT NOT NULL,
                titre VARCHAR(150) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                categories_age JSON NOT NULL,
                fichiers JSON NOT NULL,
                is_public_club TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_CS_CLUB (club_id),
                INDEX IDX_CS_CREATOR (created_by_id),
                INDEX idx_cs_public (is_public_club),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE contenu_seance ADD CONSTRAINT FK_CS_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contenu_seance ADD CONSTRAINT FK_CS_CREATOR FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE CASCADE');

        // ─── 2. contenu_seance_theme (jonction M:N) ───────────────────────────
        $this->addSql('
            CREATE TABLE contenu_seance_theme (
                contenu_seance_id INT NOT NULL,
                theme_seance_id INT NOT NULL,
                PRIMARY KEY(contenu_seance_id, theme_seance_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE contenu_seance_theme ADD CONSTRAINT FK_CST_CONTENU FOREIGN KEY (contenu_seance_id) REFERENCES contenu_seance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contenu_seance_theme ADD CONSTRAINT FK_CST_THEME FOREIGN KEY (theme_seance_id) REFERENCES theme_seance (id) ON DELETE CASCADE');

        // ─── 3. note_seance ────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE note_seance (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                seance_id INT NOT NULL,
                note SMALLINT NOT NULL DEFAULT 3,
                commentaire LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_NS_JOUEUR (joueur_id),
                INDEX IDX_NS_SEANCE (seance_id),
                UNIQUE INDEX UNQ_NS_JOUEUR_SEANCE (joueur_id, seance_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE note_seance ADD CONSTRAINT FK_NS_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE note_seance ADD CONSTRAINT FK_NS_SEANCE FOREIGN KEY (seance_id) REFERENCES seance (id) ON DELETE CASCADE');

        // ─── 4. seance_solo ────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE seance_solo (
                id INT AUTO_INCREMENT NOT NULL,
                joueur_id INT NOT NULL,
                validated_by_id INT DEFAULT NULL,
                date_solo DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
                duree_minutes SMALLINT NOT NULL DEFAULT 60,
                type VARCHAR(30) NOT NULL DEFAULT \'Shoot\',
                description LONGTEXT DEFAULT NULL,
                statut VARCHAR(20) NOT NULL DEFAULT \'pending\',
                message_coach LONGTEXT DEFAULT NULL,
                validated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_SS_JOUEUR (joueur_id),
                INDEX IDX_SS_VALIDATED_BY (validated_by_id),
                INDEX IDX_SS_STATUT (statut),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('ALTER TABLE seance_solo ADD CONSTRAINT FK_SS_JOUEUR FOREIGN KEY (joueur_id) REFERENCES joueur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE seance_solo ADD CONSTRAINT FK_SS_VALIDATED_BY FOREIGN KEY (validated_by_id) REFERENCES user (id) ON DELETE SET NULL');

        // ─── 5. ALTER seance ──────────────────────────────────────────────────
        $this->addSql('ALTER TABLE seance ADD intitule VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE seance ADD contenu_prive TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE seance ADD contenu_seance_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE seance ADD CONSTRAINT FK_SEANCE_CONTENU FOREIGN KEY (contenu_seance_id) REFERENCES contenu_seance (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_SEANCE_CONTENU ON seance (contenu_seance_id)');
    }

    public function down(Schema $schema): void
    {
        // Retirer les modifications de seance
        $this->addSql('ALTER TABLE seance DROP FOREIGN KEY FK_SEANCE_CONTENU');
        $this->addSql('DROP INDEX IDX_SEANCE_CONTENU ON seance');
        $this->addSql('ALTER TABLE seance DROP COLUMN intitule, DROP COLUMN contenu_prive, DROP COLUMN contenu_seance_id');

        // Drop tables (ordre FK inverse)
        $this->addSql('ALTER TABLE seance_solo DROP FOREIGN KEY FK_SS_JOUEUR');
        $this->addSql('ALTER TABLE seance_solo DROP FOREIGN KEY FK_SS_VALIDATED_BY');
        $this->addSql('DROP TABLE seance_solo');

        $this->addSql('ALTER TABLE note_seance DROP FOREIGN KEY FK_NS_JOUEUR');
        $this->addSql('ALTER TABLE note_seance DROP FOREIGN KEY FK_NS_SEANCE');
        $this->addSql('DROP TABLE note_seance');

        $this->addSql('ALTER TABLE contenu_seance_theme DROP FOREIGN KEY FK_CST_CONTENU');
        $this->addSql('ALTER TABLE contenu_seance_theme DROP FOREIGN KEY FK_CST_THEME');
        $this->addSql('DROP TABLE contenu_seance_theme');

        $this->addSql('ALTER TABLE contenu_seance DROP FOREIGN KEY FK_CS_CLUB');
        $this->addSql('ALTER TABLE contenu_seance DROP FOREIGN KEY FK_CS_CREATOR');
        $this->addSql('DROP TABLE contenu_seance');
    }
}
