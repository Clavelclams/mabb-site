<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * [V2.3 — 05/07/2026] Match interne à deux équipes (Stats Live).
 *
 * Ajoute UNE colonne JSON nullable `composition_interne` sur `rencontre` :
 * la répartition de l'effectif du club en Équipe A / Équipe B pour les
 * matchs ENTRAINEMENT_INTERNE et AMICAL intra-club.
 *
 * Structure : {"equipeA": {"nom": "...", "joueurs": [ids]},
 *              "equipeB": {"nom": "...", "joueurs": [ids]}}
 *
 * POURQUOI PAS DE MIGRATION DE DONNÉES :
 *   - NULL = comportement historique (une seule liste de joueuses).
 *     Toutes les rencontres existantes restent NULL → zéro régression.
 *   - Le type de match (`type_rencontre`) existe déjà depuis B23/V2.2
 *     (Version20260625*), rien à ajouter côté typage.
 *   - Les stats (action_match, evaluation_match) ne changent PAS de schéma :
 *     le rattachement au type de match se fait par JOINTURE sur rencontre
 *     (une seule source de vérité — voir ADR-0008).
 */
final class Version20260705100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'V2.3 Stats Live — colonne rencontre.composition_interne (JSON) pour le match interne à deux équipes A/B';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE rencontre ADD composition_interne JSON DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rencontre DROP composition_interne');
    }
}
