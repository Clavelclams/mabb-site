<?php

declare(strict_types=1);

namespace App\Service\Sport;

use App\Entity\Sport\Joueur;

/**
 * CategorieCalculator — [V2.4 05/07/2026]
 *
 * Calcule la catégorie FFBB d'une joueuse depuis sa DATE DE NAISSANCE,
 * pour une saison donnée. C'est la brique qui automatise le passage de
 * saison : plus besoin de re-saisir les catégories à la main.
 *
 * RÈGLE FFBB (surclassement d'âge) :
 *   âge de référence = année de FIN de saison − année de naissance
 *   (= l'âge que la joueuse atteint pendant l'année civile de fin de saison)
 *
 *   Ex : née en 2013, saison 2026-2027 → 2027 − 2013 = 14 → U15
 *        née en 2009, saison 2026-2027 → 18            → U18
 *        née en 2008, saison 2026-2027 → 19            → Senior
 *
 * CATÉGORIES :
 *   - Tranches fédérales classiques : U7, U9, U11, U13, U15, U18, Senior.
 *   - Le club aligne aussi des équipes U14/U16/U17 (championnats
 *     spécifiques) : la COMPATIBILITÉ est vérifiée par l'âge exact
 *     (une joueuse d'âge 14 peut jouer en U14, U15, U16… mais pas U13).
 */
final class CategorieCalculator
{
    /** Bornes hautes des tranches fédérales, ordonnées. */
    private const TRANCHES = [7, 9, 11, 13, 15, 18];

    /**
     * Âge FFBB de référence pour une saison ("2026-2027" → fin 2027).
     * Null si pas de date de naissance connue.
     */
    public function ageReference(Joueur $joueur, string $saison): ?int
    {
        $naissance = $joueur->getDateNaissance();
        if ($naissance === null) {
            return null;
        }
        $anneeFin = (int) explode('-', $saison)[1];
        return $anneeFin - (int) $naissance->format('Y');
    }

    /**
     * Catégorie fédérale calculée ("U13", "U18", "Senior") ou null si
     * date de naissance inconnue.
     */
    public function categorie(Joueur $joueur, string $saison): ?string
    {
        $age = $this->ageReference($joueur, $saison);
        if ($age === null) {
            return null;
        }
        foreach (self::TRANCHES as $borne) {
            if ($age <= $borne) {
                return 'U' . $borne;
            }
        }
        return 'Senior';
    }

    /**
     * Une joueuse peut-elle jouer dans une équipe de catégorie donnée ?
     *
     * Règles pragmatiques (informatif, ne bloque jamais — le coach décide) :
     *   - Équipe "U{X}"   : âge ≤ X (jouer au-dessus de son âge = OK
     *     [surclassement], jouer en dessous = NON).
     *   - Équipe Senior   : âge ≥ 15 (surclassement senior possible U16+,
     *     on tolère 15 pour les cas exceptionnels — à valider médicalement).
     *   - Équipe Loisir   : âge ≥ 15.
     *   - Date de naissance inconnue : on ne sait pas → true (pas de blocage).
     */
    public function estCompatible(Joueur $joueur, string $categorieEquipe, string $saison): bool
    {
        $age = $this->ageReference($joueur, $saison);
        if ($age === null) {
            return true;
        }
        if (preg_match('/^U(\d{1,2})/i', trim($categorieEquipe), $m)) {
            return $age <= (int) $m[1];
        }
        // Senior F / Senior H / Loisir Mixte…
        return $age >= 15;
    }

    /**
     * True si jouer dans cette équipe constitue un SURCLASSEMENT
     * (catégorie d'équipe strictement au-dessus de la catégorie calculée).
     */
    public function estSurclassement(Joueur $joueur, string $categorieEquipe, string $saison): bool
    {
        $age = $this->ageReference($joueur, $saison);
        if ($age === null) {
            return false;
        }
        if (preg_match('/^U(\d{1,2})/i', trim($categorieEquipe), $m)) {
            // Surclassée si sa tranche fédérale est inférieure à la borne équipe
            $cat = $this->categorie($joueur, $saison);
            return $cat !== null && $cat !== 'Senior'
                && (int) substr($cat, 1) < (int) $m[1];
        }
        // Équipe senior avec une mineure = surclassement
        return $age < 18;
    }
}
