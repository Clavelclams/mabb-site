<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création des 7 fiches Joueur manquantes (club id=2 — MABB).
 *
 * Ces joueuses apparaissaient dans le fichier Excel de bilans de compétences
 * mais n'existaient pas en DB → import bloqué pour elles.
 *
 * Après cette migration : relancer php bin/console app:bilan:import
 *
 * Joueuses créées :
 *   - Wendy Mbililyama         (sans licence — saison 2025-2026)
 *   - Fever Ahano              (sans licence — saison 2025-2026)
 *   - Amira Dekkiche           (BC098318 — saison 2023-2024)
 *   - Lindsay Packa            (BC092132 — saison 2023-2024)
 *   - Blessing Litongu         (BC104609 — saison 2023-2024)
 *   - Tessia Geto-Molisho      (BC098381 — saison 2023-2024)
 *   - Ibtissem Hrifa           (sans licence — saison 2023-2024)
 */
final class Version20260630100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Données — 7 fiches Joueur manquantes pour import bilans MABB (club id=2)';
    }

    public function up(Schema $schema): void
    {
        // On vérifie d'abord par licence (unique) puis par nom/prénom pour éviter tout doublon.
        // Si la joueuse existe déjà, on skip silencieusement.

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Joueuses avec licence unique — INSERT IGNORE protège contre les doublons
        $avecLicence = [
            ['prenom' => 'Amira',   'nom' => 'Dekkiche',      'licence' => 'BC098318'],
            ['prenom' => 'Lindsay', 'nom' => 'Packa',          'licence' => 'BC092132'],
            ['prenom' => 'Blessing','nom' => 'Litongu',        'licence' => 'BC104609'],
            ['prenom' => 'Tessia', 'nom' => 'Geto-Molisho',   'licence' => 'BC098381'],
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
                    'created_at' => $now,
                ]
            );
        }

        // Joueuses sans licence
        $sansLicence = [
            ['prenom' => 'Wendy',    'nom' => 'Mbililyama'],
            ['prenom' => 'Fever',    'nom' => 'Ahano'],
            ['prenom' => 'Ibtissem', 'nom' => 'Hrifa'],
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
                    'created_at' => $now,
                ]
            );
        }
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
               OR (LOWER(prenom) = 'amira'    AND LOWER(nom) = 'dekkiche')
               OR (LOWER(prenom) = 'lindsay'  AND LOWER(nom) = 'packa')
               OR (LOWER(prenom) = 'blessing' AND LOWER(nom) = 'litongu')
               OR (LOWER(prenom) = 'tessia'   AND LOWER(nom) = 'geto-molisho')
               OR (LOWER(prenom) = 'ibtissem' AND LOWER(nom) = 'hrifa')
              )
        ");
    }
}
