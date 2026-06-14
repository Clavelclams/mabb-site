<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B23 — Match d'entraînement multi-catégorie + Rencontre type ENTRAINEMENT.
 *
 * - rencontre.type enum : OFFICIEL | AMICAL | ENTRAINEMENT_INTERNE
 * - rencontre.joueurs_externes JSON : participants hors équipe officielle
 *   (pour mélanger U15/U18/Sénior dans un même match interne)
 *
 * Permet de créer "match all-star" ou "match d'entraînement" avec joueuses
 * de plusieurs catégories pour préparer la phase retour ou former à la
 * saisie stats live sur un match réel.
 */
final class Version20260612110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'B23 : rencontre + type ENTRAINEMENT/AMICAL + joueurs_externes JSON';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE rencontre ADD type_rencontre VARCHAR(30) DEFAULT 'OFFICIEL' NOT NULL");
        $this->addSql("ALTER TABLE rencontre ADD joueurs_externes JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP type_rencontre');
        $this->addSql('ALTER TABLE rencontre DROP joueurs_externes');
    }
}
