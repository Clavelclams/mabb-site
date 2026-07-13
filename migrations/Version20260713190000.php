<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suppression de equipe_coach, créée en doublon.
 *
 * L'entité CoachEquipe (table coach_equipe) existait déjà et faisait exactement le
 * même travail, en mieux : elle porte la SAISON (un coach change d'équipe d'une année
 * sur l'autre) et le RÔLE (principal ou assistant). Une liaison ManyToMany posée sur
 * Equipe savait seulement dire "cette personne coache cette équipe", ce qui est faux
 * dès qu'on passe une saison.
 *
 * Elle a été ajoutée par erreur quelques heures plus tôt (Version20260713160000),
 * faute d'avoir cherché une entité dédiée avant de coder. Rien n'a été écrit dedans
 * entre-temps : aucune donnée n'est perdue.
 *
 * Toutes les requêtes (semaine du coach, appels oubliés, compteur d'équipes) passent
 * désormais par CoachEquipe.
 */
final class Version20260713190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime equipe_coach : doublon de coach_equipe, qui porte deja la saison et le role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS equipe_coach');
    }

    public function down(Schema $schema): void
    {
        // On ne recrée pas un doublon. Si un jour il faut revenir en arrière, c'est
        // sur coach_equipe qu'il faut travailler.
        $this->throwIrreversibleMigrationException(
            'equipe_coach etait un doublon de coach_equipe. On ne le recree pas.'
        );
    }
}
