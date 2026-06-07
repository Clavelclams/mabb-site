<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bureau Manager Phase F.2 — synthèse publique avec sélecteur de rôles granulaire.
 *
 * AVANT : un simple bool `synthese_publiee` (oui/non) → visible à TOUS les membres.
 * APRÈS : un JSON `synthese_visible_roles` qui liste les rôles autorisés.
 *
 * Exemples :
 *   NULL ou []                              → non publiée (staff seul voit)
 *   ['DIRIGEANT', 'COACH']                  → visible aux dirigeants + coachs
 *   ['PARENT', 'JOUEUR']                    → visible parents + joueuses
 *   ['DIRIGEANT', 'COACH', 'STAFF',
 *    'JOUEUR', 'PARENT', 'BENEVOLE',
 *    'EMPLOYE']                              → visible à tous (équivalent ancien synthese_publiee=true)
 *
 * Permet de cibler des messages : "synthèse staff", "info parents",
 * "annonce joueuses", etc.
 */
final class Version20260606210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bureau F.2 — synthèse publique : sélecteur rôles granulaire (drop synthese_publiee, add synthese_visible_roles JSON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reunion DROP synthese_publiee');
        $this->addSql('ALTER TABLE reunion ADD synthese_visible_roles JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reunion DROP synthese_visible_roles');
        $this->addSql('ALTER TABLE reunion ADD synthese_publiee TINYINT(1) NOT NULL DEFAULT 0');
    }
}
