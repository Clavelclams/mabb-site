<?php

declare(strict_types=1);

namespace App\Controller\Vitrine;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PlanningController — page publique du planning des créneaux par saison.
 *
 * Données HARDCODÉES en V1 pour la simplicité (les créneaux changent une fois
 * par saison, pas besoin de BDD ni d'admin CMS pour ça en V1).
 *
 * Pour ajouter une nouvelle saison : copier-coller un bloc dans self::SAISONS.
 * L'ordre des saisons est CRUCIAL : la première est la saison ACTIVE par défaut.
 *
 * V2 (futur) : déplacer en BDD avec une entité PlanningSaison + admin CRUD pour
 * que les dirigeants puissent éditer sans toucher au code.
 */
class PlanningController extends AbstractController
{
    /**
     * Toutes les saisons de planning du club, ordonnées par récence (la 1ère = active).
     *
     * Format de chaque saison :
     *   - libelle : nom affiché dans le dropdown
     *   - active  : true si c'est la saison "en cours" (pastille verte)
     *   - gymnases : tableau des lieux d'entraînement avec adresse
     *   - creneaux : tableau des créneaux par équipe (jour + horaire + gymnase)
     */
    private const SAISONS = [

        '2025-2026' => [
            'libelle'  => 'Saison 2025 — 2026',
            'active'   => true,
            'gymnases' => [
                ['nom' => 'Gymnase Stéphane Diagana', 'adresse' => '94 Rue Stéphane Diagana, Amiens', 'couleur' => '#3b82f6'],
                ['nom' => 'Gymnase Léo Lagrange',     'adresse' => 'Rue Léo Lagrange, Amiens',       'couleur' => '#fc702a'],
                ['nom' => 'Gymnase Pierre de Coubertin', 'adresse' => 'Av. de la Paix, Amiens',     'couleur' => '#8b5cf6'],
            ],
            'creneaux' => [
                // Format : équipe, jour, horaire, gymnase (doit matcher gymnases.nom)
                ['equipe' => 'École de basket (5-7 ans)',    'jour' => 'Mercredi', 'horaire' => '14h00 — 15h00', 'gymnase' => 'Gymnase Léo Lagrange',         'coach' => 'Ugo'],
                ['equipe' => 'Mini-basket (U7-U9)',          'jour' => 'Mercredi', 'horaire' => '15h00 — 16h30', 'gymnase' => 'Gymnase Léo Lagrange',         'coach' => 'Ugo'],
                ['equipe' => 'U11 Féminines',                'jour' => 'Mardi',    'horaire' => '17h30 — 19h00', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Larissa'],
                ['equipe' => 'U11 Féminines',                'jour' => 'Vendredi', 'horaire' => '17h30 — 19h00', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Larissa'],
                ['equipe' => 'U13 Féminines',                'jour' => 'Lundi',    'horaire' => '18h00 — 19h30', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Leny'],
                ['equipe' => 'U13 Féminines',                'jour' => 'Jeudi',    'horaire' => '18h00 — 19h30', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Leny'],
                ['equipe' => 'U15 Féminines',                'jour' => 'Mardi',    'horaire' => '19h00 — 20h30', 'gymnase' => 'Gymnase Pierre de Coubertin',  'coach' => 'Ugo'],
                ['equipe' => 'U15 Féminines',                'jour' => 'Vendredi', 'horaire' => '19h00 — 20h30', 'gymnase' => 'Gymnase Pierre de Coubertin',  'coach' => 'Ugo'],
                ['equipe' => 'U18 Féminines',                'jour' => 'Lundi',    'horaire' => '19h30 — 21h00', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Leny'],
                ['equipe' => 'U18 Féminines',                'jour' => 'Jeudi',    'horaire' => '19h30 — 21h00', 'gymnase' => 'Gymnase Stéphane Diagana',     'coach' => 'Leny'],
                ['equipe' => 'Seniors Féminines',            'jour' => 'Mardi',    'horaire' => '20h30 — 22h00', 'gymnase' => 'Gymnase Pierre de Coubertin',  'coach' => 'Ugo'],
                ['equipe' => 'Seniors Féminines',            'jour' => 'Vendredi', 'horaire' => '20h30 — 22h00', 'gymnase' => 'Gymnase Pierre de Coubertin',  'coach' => 'Ugo'],
                ['equipe' => 'Équipe 3x3 — Tous niveaux',    'jour' => 'Samedi',   'horaire' => '10h00 — 12h00', 'gymnase' => 'Gymnase Léo Lagrange',         'coach' => 'Coachs MABB'],
            ],
        ],

        // === Pour la saison prochaine : décommenter et compléter quand prêt ===
        // '2026-2027' => [
        //     'libelle'  => 'Saison 2026 — 2027',
        //     'active'   => false,
        //     'gymnases' => [],
        //     'creneaux' => [],
        // ],

    ];

    #[Route('/planning', name: 'vitrine_planning', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Saison sélectionnée — depuis ?saison= ou saison active par défaut
        $saisonSelectionnee = (string) $request->query->get('saison', '');

        if ($saisonSelectionnee === '' || !isset(self::SAISONS[$saisonSelectionnee])) {
            // Cherche la saison "active" en premier, fallback sur la première
            $saisonSelectionnee = $this->trouverSaisonActive();
        }

        $saisonData = self::SAISONS[$saisonSelectionnee];

        // Regrouper les créneaux par équipe (pour affichage en sections)
        $creneauxParEquipe = [];
        foreach ($saisonData['creneaux'] as $c) {
            $creneauxParEquipe[$c['equipe']][] = $c;
        }

        return $this->render('vitrine/planning/index.html.twig', [
            'saisons_disponibles' => array_map(
                fn($key, $data) => ['key' => $key, 'libelle' => $data['libelle'], 'active' => $data['active'] ?? false],
                array_keys(self::SAISONS),
                self::SAISONS
            ),
            'saison_selectionnee' => $saisonSelectionnee,
            'saison_data'         => $saisonData,
            'creneaux_par_equipe' => $creneauxParEquipe,
        ]);
    }

    /**
     * Retourne la clé de la saison ACTIVE (la première marquée active=true),
     * sinon la première de la liste comme fallback.
     */
    private function trouverSaisonActive(): string
    {
        foreach (self::SAISONS as $key => $data) {
            if ($data['active'] ?? false) {
                return $key;
            }
        }
        return (string) array_key_first(self::SAISONS);
    }
}
