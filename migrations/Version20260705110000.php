<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [V2.4 — 05/07/2026] Shot chart FFBB précis.
 *
 * Ajoute sur tir_ffbb les coordonnées BRUTES du repère PDF e-Marque en
 * pour-mille (ffbb_x : 0=gauche → 1000=droite ; ffbb_y : 0=ligne de fond
 * → 1000=ligne médiane).
 *
 * Pourquoi : position_x/position_y sont issues d'un mapping affine
 * approximatif (normY*0.46+0.04) + arrondi 0-100 vers un terrain paysage
 * aux proportions différentes du doc FFBB → points visiblement décalés.
 * Les coordonnées brutes affichées sur un terrain identique au doc FFBB
 * suppriment toute transformation, donc tout décalage.
 *
 * Backfill : les lignes existantes restent NULL. Deux options :
 *   1. Re-parser (recommandé, précision maximale) :
 *      php bin/console app:process-positions-tirs --saison=2025-2026
 *   2. À défaut, le controller PIRB applique une transformation INVERSE
 *      (fallback) sur position_x/position_y — moins précis mais affichable.
 */
final class Version20260705110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V2.4 — tir_ffbb.ffbb_x / ffbb_y : coordonnées brutes repère FFBB (pour-mille) pour shot chart précis';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tir_ffbb ADD ffbb_x SMALLINT DEFAULT NULL, ADD ffbb_y SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tir_ffbb DROP ffbb_x, DROP ffbb_y');
    }
}
