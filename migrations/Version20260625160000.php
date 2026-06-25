<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shot chart — séances de tir et zones de tir (V2.3).
 *
 * DESIGN :
 *   SeanceTir = une session de saisie de tirs (entraînement ou match).
 *   ZoneTir   = un spot cliqué sur le terrain avec tentatives/réussis.
 *
 * Pourquoi deux tables séparées de ActionMatch :
 *   - ActionMatch est lié à Rencontre uniquement (FK non nullable)
 *   - Les tirs d'entraînement n'ont pas de rencontre
 *   - ZoneTir stocke des AGRÉGATS par zone (9/16) pas des actions unitaires
 *     → structure radicalement différente d'ActionMatch
 *
 * Sources (seance_tir.source) :
 *   ENTRAINEMENT : séance de tir libre déclarée par la joueuse via PIRB
 *   MATCH        : extrait de la session stats live officielle (généré auto)
 *
 * Validation :
 *   Les séances ENTRAINEMENT nécessitent validation coach (validated_by_coach).
 *   Les séances MATCH sont auto-validées depuis la session live officielle.
 */
final class Version20260625160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shot chart V2.3 — tables seance_tir et zone_tir';
    }

    public function up(Schema $schema): void
    {
        // --- TABLE seance_tir ---
        $this->addSql(<<<'SQL'
            CREATE TABLE seance_tir (
                id                  INT AUTO_INCREMENT NOT NULL,
                joueur_id           INT            NOT NULL,
                club_id             INT            NOT NULL,
                rencontre_id        INT            NULL        COMMENT 'Rencontre liée (si source=MATCH)',
                seance_id           INT            NULL        COMMENT 'Séance liée (si source=ENTRAINEMENT, optionnel)',
                source              VARCHAR(20)    NOT NULL    COMMENT 'ENTRAINEMENT | MATCH',
                date_seance         DATE           NOT NULL    COMMENT 'Date de la séance de tir',
                notes               TEXT           NULL        COMMENT 'Notes libres (contexte, conditions...)',
                validated_by_coach  TINYINT(1)     NOT NULL DEFAULT 0 COMMENT 'Validé par un coach (requis pour ENTRAINEMENT)',
                validated_by_id     INT            NULL        COMMENT 'FK User coach qui a validé',
                validated_at        DATETIME       NULL,
                created_at          DATETIME       NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT FK_seance_tir_joueur    FOREIGN KEY (joueur_id)    REFERENCES joueur(id)    ON DELETE CASCADE,
                CONSTRAINT FK_seance_tir_club      FOREIGN KEY (club_id)      REFERENCES club(id)      ON DELETE CASCADE,
                CONSTRAINT FK_seance_tir_rencontre FOREIGN KEY (rencontre_id) REFERENCES rencontre(id) ON DELETE SET NULL,
                CONSTRAINT FK_seance_tir_user      FOREIGN KEY (validated_by_id) REFERENCES user(id)  ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // Indexes pour les requêtes courantes
        $this->addSql('CREATE INDEX IDX_seance_tir_joueur    ON seance_tir (joueur_id)');
        $this->addSql('CREATE INDEX IDX_seance_tir_club      ON seance_tir (club_id)');
        $this->addSql('CREATE INDEX IDX_seance_tir_rencontre ON seance_tir (rencontre_id)');
        $this->addSql('CREATE INDEX IDX_seance_tir_date      ON seance_tir (date_seance)');
        $this->addSql('CREATE INDEX IDX_seance_tir_source    ON seance_tir (source)');

        // --- TABLE zone_tir ---
        $this->addSql(<<<'SQL'
            CREATE TABLE zone_tir (
                id              INT AUTO_INCREMENT NOT NULL,
                seance_tir_id   INT            NOT NULL,
                position_x      DOUBLE         NOT NULL COMMENT 'Position X normalisée 0-1 (gauche=0, droite=1)',
                position_y      DOUBLE         NOT NULL COMMENT 'Position Y normalisée 0-1 (fond=0, milieu=1)',
                type_tir        VARCHAR(20)    NOT NULL COMMENT '2pt_int | 2pt_ext | 3pt | lancer',
                tentatives      SMALLINT       NOT NULL DEFAULT 1,
                reussis         SMALLINT       NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT FK_zone_tir_seance FOREIGN KEY (seance_tir_id) REFERENCES seance_tir(id) ON DELETE CASCADE,
                CONSTRAINT CHK_zone_tir_ratio CHECK (reussis <= tentatives)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $this->addSql('CREATE INDEX IDX_zone_tir_seance ON zone_tir (seance_tir_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE zone_tir');
        $this->addSql('DROP TABLE seance_tir');
    }
}
