<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [15/06/2026] Fix contrainte UNIQUE rencontre pour permettre plusieurs équipes
 * d'un même club d'avoir le même numero_match FFBB dans une saison.
 *
 * Problème constaté : les seniors PRF et les U15B ont chacune leur propre
 * numérotation FFBB (#5, #7, #8...). Le numero_match=21 pour seniors n'a rien
 * à voir avec le numero_match=21 pour U15B. L'ancienne contrainte
 * UNQ_R_CLUB_NUMERO_SAISON (club_id, numero_match, saison) bloquait l'import.
 *
 * Nouvelle contrainte : (club_id, equipe_id, numero_match, saison).
 * Garde le caractère "unique par équipe par saison" attendu par la FFBB.
 *
 * Migration cohérente avec les données existantes : pas de doublon réel,
 * juste la contrainte qui doit s'élargir.
 */
final class Version20260615170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix UNIQUE rencontre : (club, équipe, numero, saison) au lieu de (club, numero, saison).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP INDEX UNQ_R_CLUB_NUMERO_SAISON');
        $this->addSql('ALTER TABLE rencontre ADD UNIQUE INDEX UNQ_R_CLUB_EQUIPE_NUMERO_SAISON (club_id, equipe_id, numero_match, saison)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP INDEX UNQ_R_CLUB_EQUIPE_NUMERO_SAISON');
        $this->addSql('ALTER TABLE rencontre ADD UNIQUE INDEX UNQ_R_CLUB_NUMERO_SAISON (club_id, numero_match, saison)');
    }
}
