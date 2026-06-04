<?php

namespace App\Gamification;

/**
 * NiveauCatalog — paliers de niveau et progression XP.
 *
 * Nommage basket classique (universel, NBA-aware) :
 *   1 Recrue · 2 Apprenti · 3 Titulaire · 4 Starter · 5 MVP · 6 Capitaine · 7 Légende
 *
 * Paliers calibrés pour une saison à 2 séances/semaine + ~20 rencontres :
 *   - Présence 100% sur la saison : ~1220 XP → Niveau 6 (Capitaine)
 *   - Présence 75%                : ~915 XP  → Niveau 5 (MVP)
 *   - Présence 50%                : ~610 XP  → Niveau 4 (Starter)
 *   - Présence 25%                : ~305 XP  → Niveau 3 (Titulaire)
 *
 * Personne ne reste bloqué Recrue toute la saison dès 10% de présence,
 * ce qui évite l'effet vexant. Le niveau 7 (Légende) reste atteignable
 * seulement avec une saison parfaite + bonus séries.
 */
class NiveauCatalog
{
    /**
     * Paliers ordonnés du moins exigeant au plus.
     * Chaque entrée : seuil XP minimum, nom, couleur (CSS hex pour les badges).
     */
    private const PALIERS = [
        ['xp_min' =>    0, 'nom' => 'Recrue',    'couleur' => '#64748b', 'icone' => 'bi-person'],
        ['xp_min' =>  100, 'nom' => 'Apprenti',  'couleur' => '#0e7490', 'icone' => 'bi-person-check'],
        ['xp_min' =>  250, 'nom' => 'Titulaire', 'couleur' => '#0062a8', 'icone' => 'bi-person-badge'],
        ['xp_min' =>  500, 'nom' => 'Starter',   'couleur' => '#7e22ce', 'icone' => 'bi-stars'],
        ['xp_min' =>  800, 'nom' => 'MVP',       'couleur' => '#fc702a', 'icone' => 'bi-trophy'],
        ['xp_min' => 1200, 'nom' => 'Capitaine', 'couleur' => '#dc2626', 'icone' => 'bi-shield-fill-check'],
        ['xp_min' => 1800, 'nom' => 'Légende',   'couleur' => '#facc15', 'icone' => 'bi-crown'],
    ];

    /**
     * Retourne le niveau correspondant à un montant d'XP.
     *
     * @return array{
     *   niveau: int,                     // 1 à 7
     *   nom: string,
     *   couleur: string,
     *   icone: string,
     *   xp_actuel: int,                  // l'XP du joueur, recopié pour commodité
     *   xp_palier_actuel: int,           // seuil bas du palier courant
     *   xp_palier_suivant: ?int,         // seuil bas du palier suivant (null si max)
     *   progres_pct: int,                // % rempli dans le palier courant (0-100)
     * }
     */
    public static function depuisXp(int $xp): array
    {
        $xp = max(0, $xp);  // pas de niveau négatif possible

        $niveau = 1;
        $palierCourant = self::PALIERS[0];
        $palierSuivant = null;

        foreach (self::PALIERS as $i => $palier) {
            if ($xp >= $palier['xp_min']) {
                $niveau = $i + 1;
                $palierCourant = $palier;
                $palierSuivant = self::PALIERS[$i + 1] ?? null;
            } else {
                break;
            }
        }

        // Calcul du % de progression dans le palier courant
        if ($palierSuivant === null) {
            // Niveau max : on est forcément à 100%
            $progres = 100;
        } else {
            $largeurPalier = $palierSuivant['xp_min'] - $palierCourant['xp_min'];
            $dansLePalier  = $xp - $palierCourant['xp_min'];
            $progres = $largeurPalier > 0
                ? (int) min(100, ($dansLePalier / $largeurPalier) * 100)
                : 100;
        }

        return [
            'niveau'             => $niveau,
            'nom'                => $palierCourant['nom'],
            'couleur'            => $palierCourant['couleur'],
            'icone'              => $palierCourant['icone'],
            'xp_actuel'          => $xp,
            'xp_palier_actuel'   => $palierCourant['xp_min'],
            'xp_palier_suivant'  => $palierSuivant['xp_min'] ?? null,
            'progres_pct'        => $progres,
        ];
    }

    /**
     * Tous les paliers (pour affichage de la grille des niveaux).
     *
     * @return array<int, array{xp_min: int, nom: string, couleur: string, icone: string, niveau: int}>
     */
    public static function tousLesPaliers(): array
    {
        $result = [];
        foreach (self::PALIERS as $i => $p) {
            $result[] = $p + ['niveau' => $i + 1];
        }
        return $result;
    }
}
