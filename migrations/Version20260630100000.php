<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création des 7 fiches Joueur manquantes + Maissa Guelfat (club id=2 — MABB).
 *
 * Ces joueuses apparaissaient dans le fichier Excel de bilans de compétences
 * mais n'existaient pas en DB → import bloqué pour elles.
 *
 * Également :
 *   — Corrige le bilan de Cler-mirice Milapie (saison 2025-2026 → 2023-2024)
 *   — Corrige la date_evaluation des bilans 2025-2026 qui avaient '2023-10-20' (copie du template 2023)
 *   — Réassigne le bilan Guelfat de Chaineze → Maissa Guelfat
 *
 * Après cette migration : relancer php bin/console app:bilan:import
 *
 * Joueuses créées :
 *   - Wendy Mbililyama         (sans licence — camp 2025-2026 — created 2026-06-04)
 *   - Fever Ahano              (sans licence — camp 2025-2026 — created 2026-06-04)
 *   - Maissa Guelfat           (sans licence — camp 2025-2026 — created 2026-06-04)
 *   - Amira Dekkiche           (BC098318 — camp 2023-2024 — created 2023-10-20)
 *   - Lindsay Packa            (BC092132 — camp 2023-2024 — created 2023-10-20)
 *   - Blessing Litongu         (BC104609 — camp 2023-2024 — created 2023-10-20)
 *   - Tessia Geto-Molisho      (BC098381 — camp 2023-2024 — created 2023-10-20)
 *   - Ibtissem Hrifa           (sans licence — camp 2023-2024 — created 2023-10-20)
 */
final class Version20260630100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Données — 8 fiches Joueur manquantes MABB + corrections bilans (dates, Cler saison, Maissa Guelfat)';
    }

    public function up(Schema $schema): void
    {
        // Dates historiques correctes selon la saison du camp
        $d2526 = '2026-06-04 00:00:00'; // Camp 2025-2026
        $d2324 = '2023-10-20 00:00:00'; // Camp 2023-2024

        // ── 1. Joueuses avec licence (2023-2024) ─────────────────────────────
        $avecLicence = [
            ['prenom' => 'Amira',    'nom' => 'Dekkiche',    'licence' => 'BC098318', 'created_at' => $d2324],
            ['prenom' => 'Lindsay',  'nom' => 'Packa',        'licence' => 'BC092132', 'created_at' => $d2324],
            ['prenom' => 'Blessing', 'nom' => 'Litongu',      'licence' => 'BC104609', 'created_at' => $d2324],
            ['prenom' => 'Tessia',   'nom' => 'Geto-Molisho', 'licence' => 'BC098381', 'created_at' => $d2324],
        ];

        foreach ($avecLicence as $j) {
            $this->addSql(
                'INSERT IGNORE INTO joueur
                    (prenom, nom, licence, is_active, profil_public, est_section_sportive, is_temporaire, created_at, club_id)
                 SELECT :prenom, :nom, :licence, 1, 0, 0, 0, :created_at, 2
                 WHERE NOT EXISTS (
                     SELECT 1 FROM joueur
                     WHERE LOWER(prenom) = LOWER(:prenom) AND LOWER(nom) = LOWER(:nom) AND club_id = 2
                 )',
                [
                    'prenom'     => $j['prenom'],
                    'nom'        => $j['nom'],
                    'licence'    => $j['licence'],
                    'created_at' => $j['created_at'],
                ]
            );
        }

        // ── 2. Joueuses sans licence ─────────────────────────────────────────
        // Inclut Maissa Guelfat (sœur de Chaineze — bilan 2025-2026 mal linké)
        $sansLicence = [
            ['prenom' => 'Wendy',    'nom' => 'Mbililyama', 'created_at' => $d2526], // camp 2025-2026
            ['prenom' => 'Fever',    'nom' => 'Ahano',      'created_at' => $d2526], // camp 2025-2026
            ['prenom' => 'Maissa',   'nom' => 'Guelfat',    'created_at' => $d2526], // camp 2025-2026 — bilan actuellement sur Chaineze
            ['prenom' => 'Ibtissem', 'nom' => 'Hrifa',      'created_at' => $d2324], // camp 2023-2024
        ];

        foreach ($sansLicence as $j) {
            $this->addSql(
                'INSERT INTO joueur
                    (prenom, nom, is_active, profil_public, est_section_sportive, is_temporaire, created_at, club_id)
                 SELECT :prenom, :nom, 1, 0, 0, 0, :created_at, 2
                 WHERE NOT EXISTS (
                     SELECT 1 FROM joueur
                     WHERE LOWER(prenom) = LOWER(:prenom) AND LOWER(nom) = LOWER(:nom) AND club_id = 2
                 )',
                [
                    'prenom'     => $j['prenom'],
                    'nom'        => $j['nom'],
                    'created_at' => $j['created_at'],
                ]
            );
        }

        // ── 3. Fix Cler-mirice Milapie : bilan déplacé en 2023-2024 ─────────
        // Dans le fichier Excel, sa fiche était mal classée en 2025-2026.
        // Elle participait au camp d'octobre 2023 → saison 2023-2024.
        $this->addSql("
            UPDATE bilan_competence bc
            INNER JOIN joueur j ON j.id = bc.joueur_id AND j.club_id = 2
            SET bc.saison = '2023-2024',
                bc.date_evaluation = '2023-10-20'
            WHERE bc.saison = '2025-2026'
              AND LOWER(j.prenom) LIKE 'cler%'
              AND LOWER(j.nom) = 'milapie'
        ");

        // ── 4. Fix date_evaluation pour bilans 2025-2026 avec mauvaise date ──
        // Lors de l'extraction Excel, la date_evaluation '2023-10-20' a été
        // copiée par erreur depuis le template 2023. Le camp 2025-2026 était
        // le 4 juin 2026.
        // NB : s'exécute après le fix Cler (dont le bilan est désormais en 2023-2024)
        $this->addSql("
            UPDATE bilan_competence bc
            INNER JOIN joueur j ON j.id = bc.joueur_id AND j.club_id = 2
            SET bc.date_evaluation = '2026-06-04'
            WHERE bc.saison = '2025-2026'
              AND bc.date_evaluation = '2023-10-20'
        ");

        // ── 5. Réassigner le bilan Guelfat : Chaineze → Maissa ───────────────
        // Le bilan importé sous "Rahma Guelfat" avait été linké à Chaineze Guelfat
        // via fallback nom uniquement. Ce bilan appartient à sa sœur Maissa.
        // On doit s'assurer que Maissa est créée (step 2) avant d'exécuter ceci.
        $this->addSql("
            UPDATE bilan_competence bc
            INNER JOIN joueur chaineze
                ON chaineze.id = bc.joueur_id
               AND LOWER(chaineze.nom) = 'guelfat'
               AND LOWER(chaineze.prenom) LIKE 'chain%'
               AND chaineze.club_id = 2
            INNER JOIN joueur maissa
                ON LOWER(maissa.nom) = 'guelfat'
               AND LOWER(maissa.prenom) = 'maissa'
               AND maissa.club_id = 2
            SET bc.joueur_id = maissa.id
            WHERE bc.saison = '2025-2026'
        ");
    }

    public function down(Schema $schema): void
    {
        // Supprime uniquement les fiches sans user lié (évite de casser des comptes PIRB)
        $this->addSql("
            DELETE FROM joueur
            WHERE club_id = 2
              AND user_id IS NULL
              AND (
                  (LOWER(prenom) = 'wendy'    AND LOWER(nom) = 'mbililyama')
               OR (LOWER(prenom) = 'fever'    AND LOWER(nom) = 'ahano')
               OR (LOWER(prenom) = 'maissa'   AND LOWER(nom) = 'guelfat')
               OR (LOWER(prenom) = 'ibtissem' AND LOWER(nom) = 'hrifa')
               OR (LOWER(prenom) = 'amira'    AND LOWER(nom) = 'dekkiche')
               OR (LOWER(prenom) = 'lindsay'  AND LOWER(nom) = 'packa')
               OR (LOWER(prenom) = 'blessing' AND LOWER(nom) = 'litongu')
               OR (LOWER(prenom) = 'tessia'   AND LOWER(nom) = 'geto-molisho')
              )
        ");
        // Note : les corrections de bilans (dates, saison Cler, réassignation Maissa)
        // ne sont pas inversées — trop risqué de corrompre des données manuellement modifiées.
    }
}
