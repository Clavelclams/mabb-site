<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [V2.4m 10/07/2026] Seed des 3 SECTEURS officiels du club MABB
 * (nommage confirmé par Clavel) :
 *   1. OUEST (ETOUVIE)
 *   2. NORD
 *   3. SUD
 *
 * Idempotent : n'insère que si le secteur n'existe pas déjà pour le club
 * (slug 'mabb'). Ne touche à rien si le club n'existe pas (env vierge).
 * Les responsables de secteur se renseignent dans l'UI (classeur →
 * panneau « Secteurs & responsables »).
 */
final class Version20260710123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed secteurs MABB : OUEST (ETOUVIE) / NORD / SUD';
    }

    public function up(Schema $schema): void
    {
        foreach ([['OUEST (ETOUVIE)', 1], ['NORD', 2], ['SUD', 3]] as [$nom, $ordre]) {
            $nomSql = str_replace("'", "''", $nom);
            $this->addSql(
                "INSERT INTO sport_secteur (nom, ordre, club_id)
                 SELECT '{$nomSql}', {$ordre}, c.id FROM club c
                 WHERE c.slug = 'mabb'
                   AND NOT EXISTS (
                       SELECT 1 FROM sport_secteur s
                       WHERE s.club_id = c.id AND s.nom = '{$nomSql}'
                   )"
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE s FROM sport_secteur s
                       INNER JOIN club c ON c.id = s.club_id
                       WHERE c.slug = 'mabb' AND s.nom IN ('OUEST (ETOUVIE)', 'NORD', 'SUD')");
    }
}
