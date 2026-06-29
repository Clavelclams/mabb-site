<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BilanCompetence — Bilan de compétences basketballistiques digitalisé.
 *
 * Reproduit la fiche Excel "bilan vierge.xlsx" en base de données :
 *   - 4 catégories de critères (22 critères, notes TINYINT 1-10)
 *   - Informations administratives (licence, sécurité sociale, santé)
 *   - Sidebar (mensurations, participation, profil de jeu)
 *   - Champs texte libres (points forts, vigilance, axes, remarques)
 *
 * Workflow : BROUILLON → coach remplit → VALIDE → joueuse peut voir depuis PIRB
 *
 * Choix techniques :
 *   - TINYINT pour les scores (valeurs 1-10, max 127 signé)
 *   - DECIMAL(5,2) pour le poids (ex: 65.50 kg)
 *   - DATE pour dateEvaluation (pas besoin de l'heure)
 *   - Index sur (joueur_id, saison) pour les lookups fréquents
 *   - ON DELETE CASCADE sur joueur et club (intégrité)
 */
final class Version20260629110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BilanCompetence — Fiche bilan basketballistique digitalisée';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE bilan_competence (
                id                    INT AUTO_INCREMENT NOT NULL,
                joueur_id             INT NOT NULL,
                coach_id              INT DEFAULT NULL,
                club_id               INT NOT NULL,

                -- Métadonnées
                saison                VARCHAR(12) NOT NULL,
                contexte              VARCHAR(80) DEFAULT NULL,
                date_evaluation       DATE DEFAULT NULL,
                statut                VARCHAR(20) NOT NULL DEFAULT 'brouillon',
                created_at            DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at            DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',

                -- Renseignements administratifs
                numero_licence        VARCHAR(30) DEFAULT NULL,
                num_secu_sociale      VARCHAR(25) DEFAULT NULL,
                mutuelle              VARCHAR(120) DEFAULT NULL,
                probleme_sante        LONGTEXT DEFAULT NULL,
                allergies             LONGTEXT DEFAULT NULL,
                regime_alimentaire    LONGTEXT DEFAULT NULL,

                -- Participation / sidebar
                nb_seances            SMALLINT DEFAULT NULL,
                presence_type         VARCHAR(40) DEFAULT NULL,
                taille                SMALLINT DEFAULT NULL,
                poids                 DECIMAL(5,2) DEFAULT NULL,
                envergure             SMALLINT DEFAULT NULL,
                taille_assise         SMALLINT DEFAULT NULL,
                pointure              SMALLINT DEFAULT NULL,
                main_forte            VARCHAR(15) DEFAULT NULL,
                profil_de_jeu         LONGTEXT DEFAULT NULL,

                -- Vie quotidienne / Internat (5 critères)
                vq_respect_regles     TINYINT DEFAULT NULL,
                vq_ponctualite        TINYINT DEFAULT NULL,
                vq_discipline         TINYINT DEFAULT NULL,
                vq_vie_groupe         TINYINT DEFAULT NULL,
                vq_rangement          TINYINT DEFAULT NULL,

                -- Qualités Mentales (6 critères)
                qm_enthousiasme       TINYINT DEFAULT NULL,
                qm_determination      TINYINT DEFAULT NULL,
                qm_confiance          TINYINT DEFAULT NULL,
                qm_curiosite          TINYINT DEFAULT NULL,
                qm_autonomie          TINYINT DEFAULT NULL,
                qm_concentration      TINYINT DEFAULT NULL,

                -- Qualités Technico-Tactiques (8 critères)
                qtt_adresse           TINYINT DEFAULT NULL,
                qtt_efficacite_panier TINYINT DEFAULT NULL,
                qtt_aisance           TINYINT DEFAULT NULL,
                qtt_jeu_sans_ballons  TINYINT DEFAULT NULL,
                qtt_comprehension     TINYINT DEFAULT NULL,
                qtt_defense           TINYINT DEFAULT NULL,
                qtt_rebond_catcher    TINYINT DEFAULT NULL,
                qtt_rebond_transiter  TINYINT DEFAULT NULL,

                -- Qualités Physiques (3 critères)
                qp_enchainement       TINYINT DEFAULT NULL,
                qp_vitesse            TINYINT DEFAULT NULL,
                qp_soins_du_corps     TINYINT DEFAULT NULL,

                -- Champs texte libres
                points_forts          LONGTEXT DEFAULT NULL,
                alerte_medicale       LONGTEXT DEFAULT NULL,
                points_vigilance      LONGTEXT DEFAULT NULL,
                axes_travail          LONGTEXT DEFAULT NULL,
                bilan_remarques       LONGTEXT DEFAULT NULL,

                PRIMARY KEY (id),
                INDEX idx_bilan_joueur_saison (joueur_id, saison),
                INDEX idx_bilan_club (club_id),

                CONSTRAINT FK_bilan_joueur FOREIGN KEY (joueur_id)
                    REFERENCES joueur(id) ON DELETE CASCADE,
                CONSTRAINT FK_bilan_coach  FOREIGN KEY (coach_id)
                    REFERENCES user(id) ON DELETE SET NULL,
                CONSTRAINT FK_bilan_club   FOREIGN KEY (club_id)
                    REFERENCES club(id) ON DELETE CASCADE

            ) DEFAULT CHARACTER SET utf8mb4
              COLLATE `utf8mb4_unicode_ci`
              ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bilan_competence');
    }
}
