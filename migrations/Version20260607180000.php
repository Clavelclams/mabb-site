<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stats Live V2.1f — Effectif du match.
 *
 * Ajoute joueurs_non_convoques JSON sur rencontre pour stocker les IDs
 * des joueuses qui NE jouent PAS au match (blessées, examens, absentes).
 *
 * Pourquoi JSON et pas une entité dédiée ?
 *   - Donnée strictement liée à UNE rencontre, pas d'historique multi-saison
 *   - Pas de jointures à faire, requêtes simples
 *   - Si on a 15-20 joueuses max → array de quelques IDs, parfait
 */
final class Version20260607180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stats Live V2.1f — joueurs_non_convoques JSON sur rencontre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre ADD joueurs_non_convoques JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP joueurs_non_convoques');
    }
}
