<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration : Joueur.licence devient UNIQUE — PIRB V1.3.
 *
 * Empêche les doublons : 2 fiches Joueur ne peuvent plus avoir le même
 * numéro de licence FFBB. Permet le match automatique User↔Joueur au
 * signup PIRB via le numéro de licence (clé tertiaire après email).
 *
 * MySQL accepte plusieurs valeurs NULL dans une colonne UNIQUE — donc
 * les joueurs sans licence (jeunes en formation, bénévoles, etc.) ne
 * sont pas bloqués.
 *
 * Si la migration plante avec "Duplicate entry" → il y a déjà des
 * doublons en BDD à nettoyer avant. Lance :
 *   SELECT licence, COUNT(*) c FROM joueur WHERE licence IS NOT NULL
 *   GROUP BY licence HAVING c > 1;
 */
final class Version20260607230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PIRB V1.3 — UNIQUE constraint sur joueur.licence (anti-doublon FFBB)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_joueur_licence ON joueur (licence)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_joueur_licence ON joueur');
    }
}
