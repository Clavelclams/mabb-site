<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B-Seances V1 (Part 1/2) — Table theme_seance + pré-population des thèmes système.
 *
 * Thèmes basket pré-définis dans 4 groupes :
 *   - Attaque (9 thèmes)
 *   - Défense (7 thèmes)
 *   - Collectif (6 thèmes)
 *   - Physique / Technique (6 thèmes)
 *
 * Les thèmes système ont is_systeme=1, club_id=NULL.
 * Ils ne peuvent pas être supprimés depuis l'interface (contrainte applicative).
 * L'admin peut ajouter des thèmes custom (is_systeme=0, club_id=X).
 */
final class Version20260625130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B-Seances — Table theme_seance + 28 thèmes basket système.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE theme_seance (
                id INT AUTO_INCREMENT NOT NULL,
                club_id INT DEFAULT NULL,
                libelle VARCHAR(80) NOT NULL,
                slug VARCHAR(60) NOT NULL,
                groupe VARCHAR(30) NOT NULL,
                is_systeme TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNQ_TS_SLUG (slug),
                INDEX IDX_TS_CLUB (club_id),
                INDEX IDX_TS_GROUPE (groupe),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        $this->addSql('
            ALTER TABLE theme_seance
            ADD CONSTRAINT FK_TS_CLUB FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE
        ');

        // ─── Insertion des thèmes système ────────────────────────────────────
        $now = date('Y-m-d H:i:s');

        $themes = [
            // Attaque
            ['Jeu en passe',           'jeu-passe',            'Attaque'],
            ['Dribble / pénétration',  'dribble-penetration',  'Attaque'],
            ['1c1 offensif',            '1c1-offensif',         'Attaque'],
            ['Tir mi-distance',         'tir-mi-distance',      'Attaque'],
            ['Tir à 3 points',          'tir-3pts',             'Attaque'],
            ['Lay-up / Finition',       'layup-finition',       'Attaque'],
            ['Pick & Roll',              'pick-and-roll',        'Attaque'],
            ['Transition offensive',    'transition-offensive', 'Attaque'],
            ['Jeu en isolation',        'isolation',            'Attaque'],
            // Défense
            ['Défense porteur',         'defense-porteur',      'Défense'],
            ['Défense non-porteur',     'defense-non-porteur',  'Défense'],
            ['Aide défensive',          'aide-defensive',       'Défense'],
            ['Défense en zone',         'defense-zone',         'Défense'],
            ['Press défensif',          'press-defensif',       'Défense'],
            ['1c1 défensif',            '1c1-defensif',         'Défense'],
            ['Transition défensive',    'transition-defensive', 'Défense'],
            // Collectif
            ['Système offensif',        'systeme-offensif',     'Collectif'],
            ['Sortie de zone',          'sortie-zone',          'Collectif'],
            ['Rebond offensif',         'rebond-offensif',      'Collectif'],
            ['Rebond défensif',         'rebond-defensif',      'Collectif'],
            ['Contre-attaque',          'contre-attaque',       'Collectif'],
            ['Fin de match',            'fin-de-match',         'Collectif'],
            // Physique / Technique
            ['Coordination',            'coordination',         'Physique / Technique'],
            ['Cardio / Conditioning',   'cardio',               'Physique / Technique'],
            ['Vitesse / Explosivité',   'vitesse-explosivite',  'Physique / Technique'],
            ['Proprioception',          'proprioception',       'Physique / Technique'],
            ['Échauffement',            'echauffement',         'Physique / Technique'],
            ['Retour au calme',         'retour-calme',         'Physique / Technique'],
        ];

        foreach ($themes as [$libelle, $slug, $groupe]) {
            $this->addSql(
                'INSERT INTO theme_seance (libelle, slug, groupe, is_systeme, created_at) VALUES (?, ?, ?, 1, ?)',
                [$libelle, $slug, $groupe, $now]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE theme_seance DROP FOREIGN KEY FK_TS_CLUB');
        $this->addSql('DROP TABLE theme_seance');
    }
}
