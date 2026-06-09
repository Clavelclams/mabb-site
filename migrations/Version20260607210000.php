<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Profil scouting PIRB V1.2a.
 *
 * Ajoute 3 champs sur joueur pour transformer PIRB en outil scouting :
 *   - bio (text, nullable) : description libre de la joueuse, style Instagram
 *   - profil_public (bool, default 0) : visibilité externe — anonyme par défaut
 *     (la joueuse opt-in pour être scoutée)
 *   - liens_sociaux (json, nullable) : {instagram, tiktok, youtube, twitter, linkedin}
 *
 * Hommage à PIRB Scouting (@pirb_scouting) — l'outil que MABB construit pour
 * faciliter le travail des scouts comme Pierre.
 */
final class Version20260607210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PIRB V1.2a — Profil scouting : bio + profilPublic + liensSociaux sur joueur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD bio LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE joueur ADD profil_public TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE joueur ADD liens_sociaux JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur DROP bio');
        $this->addSql('ALTER TABLE joueur DROP profil_public');
        $this->addSql('ALTER TABLE joueur DROP liens_sociaux');
    }
}
