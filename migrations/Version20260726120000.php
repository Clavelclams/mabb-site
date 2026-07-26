<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [V2.4p 26/07/2026] Colonne source sur evaluation_match : d'où vient l'éval ?
 *   - 'live'   : agrégée depuis Stats Live
 *   - 'ffbb'   : importée du PDF officiel FFBB (OCR) — données partielles
 *   - 'manuel' : saisie coach (formulaire ou Excel) — défaut
 *
 * BACKFILL des lignes existantes, du plus sûr au plus fin :
 *   1. Tout le monde démarre à 'manuel' (défaut de la colonne).
 *   2. 'ffbb' : les imports OCR taguaient notes_coach avec
 *      « [OCR FFBB import ... ] » — marqueur fiable.
 *   3. 'live' : une éval dont la paire (joueur, rencontre) a des ActionMatch
 *      vient de l'agrégateur live (l'import FFBB et la saisie manuelle ne
 *      créent jamais d'ActionMatch).
 *   L'ordre 2 puis 3 fait gagner le 'live' si les deux sources ont écrit sur
 *   la même éval : la donnée live est la plus riche, c'est elle qu'on montre.
 *
 * Migration écrite à la main, zéro drift.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Toggle Live/FFBB : colonne source sur evaluation_match + backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE evaluation_match ADD source VARCHAR(10) DEFAULT 'manuel' NOT NULL");

        // Backfill 'ffbb' via le marqueur d'import OCR dans notes_coach
        $this->addSql("UPDATE evaluation_match SET source = 'ffbb' WHERE notes_coach LIKE '%OCR FFBB import%'");

        // Backfill 'live' via l'existence d'ActionMatch (gagne sur 'ffbb')
        $this->addSql("UPDATE evaluation_match em
            SET em.source = 'live'
            WHERE EXISTS (
                SELECT 1 FROM action_match am
                WHERE am.rencontre_id = em.rencontre_id
                  AND am.joueur_id = em.joueur_id
            )");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_match DROP source');
    }
}
