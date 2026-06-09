<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Badges épinglés PIRB V1.2b.
 *
 * Ajoute joueur.badges_epingles (JSON nullable) — array de 3 codes badges max
 * que la joueuse choisit d'afficher en avant sur son profil PIRB.
 *
 * Les codes pointent vers BadgeCatalog (ex: 'A_STREAK_10', 'C_FIRST_MISSION').
 * La validation max 3 est faite côté entité dans setBadgesEpingles().
 */
final class Version20260607220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PIRB V1.2b — Badges épinglés (3 max) sur profil joueuse';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD badges_epingles JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur DROP badges_epingles');
    }
}
