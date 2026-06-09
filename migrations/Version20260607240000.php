<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Joueur.highlights JSON — PIRB V1.2c.
 *
 * Permet à la joueuse d'ajouter jusqu'à 5 liens vers ses meilleures actions
 * vidéo (YouTube, Instagram, TikTok). Affichés sur son profil PIRB et
 * embeddés en aperçu sur le profil public consultable.
 *
 * Structure JSON : array de objects {url, titre, date}
 *   Ex: [{"url":"https://youtu.be/abc","titre":"3 pts contre Longueau","date":"2026-03-12"}]
 */
final class Version20260607240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PIRB V1.2c — joueur.highlights JSON (liens vidéo profil scouting)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur ADD highlights JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE joueur DROP highlights');
    }
}
