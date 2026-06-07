<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\EvaluationMatchRepository;

/**
 * EvaluationCalculator — service d'agrégation des stats Éval sur une saison.
 *
 * RESPONSABILITÉS :
 *   - Calculer les MOYENNES sur une saison (eval, points, rebonds, etc.)
 *   - Calculer les TOTAUX cumulés sur une saison
 *   - Déterminer si une joueuse a "match dominant" (eval >= 15)
 *
 * POURQUOI UN SERVICE SÉPARÉ DU REPOSITORY :
 *   Le Repository fait du DATA ACCESS (SELECT ... FROM evaluation_match).
 *   Le Service fait de la LOGIQUE MÉTIER (calcul de moyennes, agrégats).
 *   Cette séparation suit le pattern "Repository pour la donnée, Service
 *   pour le sens". Si demain on change le calcul de moyenne (ex: pondéré
 *   par les minutes jouées), on ne touche QUE ce service.
 *
 * TESTABILITÉ :
 *   Le service ne dépend que d'un repository. En test unitaire, on peut
 *   mocker EvaluationMatchRepository et fournir des EvaluationMatch
 *   en mémoire — pas besoin de bootstraper Doctrine.
 */
final class EvaluationCalculator
{
    public function __construct(
        private readonly EvaluationMatchRepository $evaluationMatchRepository,
    ) {}

    /**
     * Calcule les MOYENNES par match d'une joueuse sur une saison.
     *
     * Retourne null pour chaque stat si aucun match joué (= évite "NaN", "0.0", etc.).
     * Le caller (Twig) peut afficher "—" propre.
     *
     * @return array{
     *     nb_matchs: int,
     *     eval_moyenne: float|null,
     *     points_moyenne: float|null,
     *     rebonds_moyenne: float|null,
     *     passes_moyenne: float|null,
     *     interceptions_moyenne: float|null,
     *     contres_moyenne: float|null,
     *     minutes_moyenne: float|null,
     *     pourcentage_tirs: float|null,
     *     nb_matchs_dominants: int,
     *     meilleure_eval: int|null,
     * }
     */
    public function moyennesSaison(Joueur $joueur, string $saison): array
    {
        $evals = $this->evaluationMatchRepository->evaluationsSaison($joueur, $saison);
        return $this->agreger($evals);
    }

    /**
     * Calcule les moyennes sur les N dernières évals (toutes saisons).
     *
     * Utile pour "forme du moment" — un coach veut savoir comment la joueuse
     * tourne sur ses 5 derniers matchs, pas sur toute la saison.
     */
    public function moyennesRecentes(Joueur $joueur, int $limit = 5): array
    {
        $evals = $this->evaluationMatchRepository->evaluationsRecentes($joueur, $limit);
        return $this->agreger($evals);
    }

    /**
     * Cœur du calcul d'agrégation — extrait pour DRY entre saison et récent.
     *
     * Itération simple sur les évals. Pour un effectif typique (20 matchs/saison max),
     * c'est suffisant en performance. Si un jour on a 100+ matchs, on basculera
     * sur des SUM() SQL directs dans le Repository.
     *
     * @param EvaluationMatch[] $evals
     */
    private function agreger(array $evals): array
    {
        $nb = count($evals);

        if ($nb === 0) {
            return [
                'nb_matchs'             => 0,
                'eval_moyenne'          => null,
                'points_moyenne'        => null,
                'rebonds_moyenne'       => null,
                'passes_moyenne'        => null,
                'interceptions_moyenne' => null,
                'contres_moyenne'       => null,
                'minutes_moyenne'       => null,
                'pourcentage_tirs'      => null,
                'nb_matchs_dominants'   => 0,
                'meilleure_eval'        => null,
            ];
        }

        // Compteurs d'agrégation
        $totalEval     = 0;
        $totalPoints   = 0;
        $totalRebonds  = 0;
        $totalPasses   = 0;
        $totalIntercep = 0;
        $totalContres  = 0;
        $totalMinutes  = 0;
        $totalTirsTentes  = 0;
        $totalTirsReussis = 0;
        $nbDominants   = 0;
        $meilleureEval = PHP_INT_MIN;

        foreach ($evals as $e) {
            $eval = $e->getEval();
            $totalEval     += $eval;
            $totalPoints   += $e->getPoints();
            $totalRebonds  += $e->getRebonds();
            $totalPasses   += $e->getPassesDecisives();
            $totalIntercep += $e->getInterceptions();
            $totalContres  += $e->getContres();
            $totalMinutes  += $e->getMinutesJouees();
            $totalTirsTentes  += $e->getTirs2ptsTentes() + $e->getTirs3ptsTentes();
            $totalTirsReussis += $e->getTirs2ptsReussis() + $e->getTirs3ptsReussis();

            // Eval >= 15 = match dominant (référence FIBA pour MVP de match)
            if ($eval >= 15) {
                $nbDominants++;
            }
            if ($eval > $meilleureEval) {
                $meilleureEval = $eval;
            }
        }

        return [
            'nb_matchs'             => $nb,
            'eval_moyenne'          => round($totalEval / $nb, 1),
            'points_moyenne'        => round($totalPoints / $nb, 1),
            'rebonds_moyenne'       => round($totalRebonds / $nb, 1),
            'passes_moyenne'        => round($totalPasses / $nb, 1),
            'interceptions_moyenne' => round($totalIntercep / $nb, 1),
            'contres_moyenne'       => round($totalContres / $nb, 1),
            'minutes_moyenne'       => round($totalMinutes / $nb, 1),
            'pourcentage_tirs'      => $totalTirsTentes > 0
                                        ? round($totalTirsReussis / $totalTirsTentes * 100, 1)
                                        : null,
            'nb_matchs_dominants'   => $nbDominants,
            'meilleure_eval'        => $meilleureEval,
        ];
    }

    /**
     * Codes couleur pour l'affichage Twig — convention design système.
     *
     *   eval >= 15  → vert (match dominant)
     *   eval >=  5  → orange (solide)
     *   eval >=  0  → gris (correct sans plus)
     *   eval <   0  → rouge (match raté)
     */
    public static function couleurEval(int|float|null $eval): string
    {
        if ($eval === null) return '#94a3b8';     // slate-400
        if ($eval >= 15)    return '#16a34a';     // green-600
        if ($eval >= 5)     return '#f59e0b';     // amber-500
        if ($eval >= 0)     return '#64748b';     // slate-500
        return '#dc2626';                          // red-600
    }
}
