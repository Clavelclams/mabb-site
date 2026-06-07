<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\EvaluationMatchRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ActionMatchAggregator — pont entre saisie LIVE et stats FIBA agrégées.
 *
 * RÔLE :
 *   Convertit une suite d'ActionMatch (saisie live granulaire) en
 *   compteurs FIBA agrégés (EvaluationMatch). Permet :
 *     1. D'afficher les stats agrégées sur la fiche joueuse
 *     2. De générer un PDF résumé style FFBB
 *     3. D'archiver/figer un match terminé
 *
 * COHABITATION DES 2 SOURCES :
 *   - Si un match a des ActionMatch → on agrège depuis là (vérité granulaire)
 *   - Si un match n'a QUE EvaluationMatch (saisie manuelle/import) → on garde
 *     l'EvaluationMatch existante (la saisie live n'a jamais eu lieu)
 *
 * IDEMPOTENCE :
 *   Appeler genererEvaluationMatch() plusieurs fois pour la même paire
 *   (joueur, rencontre) → soit on update l'existante, soit on en crée une.
 *   Pas de doublon. Sûr à appeler après chaque saisie d'action.
 *
 * PERFORMANCE :
 *   Méthode agreger() utilise comptageActionsParType() qui fait un seul
 *   COUNT GROUP BY au lieu d'hydrater toutes les actions. ~10ms pour
 *   un joueur qui a 30 actions sur un match.
 */
final class ActionMatchAggregator
{
    public function __construct(
        private readonly ActionMatchRepository $actionMatchRepository,
        private readonly EvaluationMatchRepository $evaluationMatchRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Agrège les ActionMatch d'un joueur sur une rencontre en compteurs FIBA.
     *
     * Retourne un array prêt à être appliqué à une EvaluationMatch
     * (via setters successifs ou hydratation).
     *
     * @return array{
     *     tirs2ptsReussis: int, tirs2ptsTentes: int,
     *     tirs3ptsReussis: int, tirs3ptsTentes: int,
     *     lancersReussis: int, lancersTentes: int,
     *     rebondsOffensifs: int, rebondsDefensifs: int,
     *     passesDecisives: int, interceptions: int,
     *     contres: int, contresSubis: int,
     *     fautesCommises: int, fautesProvoquees: int,
     *     pertesBalle: int,
     *     minutesJouees: int, isStarter: bool,
     * }
     */
    public function agreger(Joueur $joueur, Rencontre $rencontre): array
    {
        // Un seul COUNT GROUP BY au lieu de hydrater toutes les actions
        $comptages = $this->actionMatchRepository->comptageActionsParType($joueur, $rencontre);

        // Calcul des tirs 2pts : intérieurs (raquette) + extérieurs (mi-distance)
        // Reflète le découpage Easy Stats / FFBB
        $t2IntReussis = $comptages[ActionMatch::TYPE_TIR_2PT_INT_REUSSI] ?? 0;
        $t2IntRates   = $comptages[ActionMatch::TYPE_TIR_2PT_INT_RATE]   ?? 0;
        $t2ExtReussis = $comptages[ActionMatch::TYPE_TIR_2PT_EXT_REUSSI] ?? 0;
        $t2ExtRates   = $comptages[ActionMatch::TYPE_TIR_2PT_EXT_RATE]   ?? 0;

        $tirs2ptsReussis = $t2IntReussis + $t2ExtReussis;
        $tirs2ptsTentes  = $tirs2ptsReussis + $t2IntRates + $t2ExtRates;

        $tirs3ptsReussis = $comptages[ActionMatch::TYPE_TIR_3PT_REUSSI] ?? 0;
        $tirs3ptsTentes  = $tirs3ptsReussis + ($comptages[ActionMatch::TYPE_TIR_3PT_RATE] ?? 0);

        $lancersReussis = $comptages[ActionMatch::TYPE_LANCER_REUSSI] ?? 0;
        $lancersTentes  = $lancersReussis + ($comptages[ActionMatch::TYPE_LANCER_RATE] ?? 0);

        // Minutes jouées : durée entre les ENTREE et SORTIE. Calcul plus précis
        // que comptage simple. À implémenter en Phase 2 (saisie live).
        // V1 : on prend le total des ENTREE (proxy temporaire).
        $minutesJouees = $this->calculerMinutesJouees($joueur, $rencontre);

        // Titulaire : la joueuse est titulaire si sa première ENTREE est à 0:00 QT1
        // En V1, on regarde simplement s'il y a une ENTREE dans le 1er quart.
        $isStarter = $this->detecterTitulaire($joueur, $rencontre);

        return [
            'tirs2ptsReussis'   => $tirs2ptsReussis,
            'tirs2ptsTentes'    => $tirs2ptsTentes,
            'tirs3ptsReussis'   => $tirs3ptsReussis,
            'tirs3ptsTentes'    => $tirs3ptsTentes,
            'lancersReussis'    => $lancersReussis,
            'lancersTentes'     => $lancersTentes,
            'rebondsOffensifs'  => $comptages[ActionMatch::TYPE_REBOND_OFFENSIF]  ?? 0,
            'rebondsDefensifs'  => $comptages[ActionMatch::TYPE_REBOND_DEFENSIF]  ?? 0,
            'passesDecisives'   => $comptages[ActionMatch::TYPE_PASSE_DECISIVE]   ?? 0,
            'interceptions'     => $comptages[ActionMatch::TYPE_INTERCEPTION]     ?? 0,
            'contres'           => $comptages[ActionMatch::TYPE_CONTRE]           ?? 0,
            'contresSubis'      => $comptages[ActionMatch::TYPE_CONTRE_SUBI]      ?? 0,
            'fautesCommises'    => $comptages[ActionMatch::TYPE_FAUTE_COMMISE]    ?? 0,
            'fautesProvoquees'  => $comptages[ActionMatch::TYPE_FAUTE_PROVOQUEE]  ?? 0,
            'pertesBalle'       => $comptages[ActionMatch::TYPE_PERTE_BALLE]      ?? 0,
            'minutesJouees'     => $minutesJouees,
            'isStarter'         => $isStarter,
        ];
    }

    /**
     * Génère ou met à jour l'EvaluationMatch d'un joueur sur une rencontre
     * depuis ses ActionMatch.
     *
     * Idempotent : si une EvaluationMatch existe déjà pour la paire (joueur, rencontre),
     * on l'UPDATE. Sinon on en crée une nouvelle.
     *
     * Le caller doit appeler $em->flush() après — le service ne flush pas
     * lui-même (pattern de séparation des responsabilités).
     *
     * @return EvaluationMatch L'entité créée ou mise à jour (non flushée)
     */
    public function genererEvaluationMatch(Joueur $joueur, Rencontre $rencontre): EvaluationMatch
    {
        $eval = $this->evaluationMatchRepository->findOneByJoueurAndRencontre($joueur, $rencontre);
        $estNouvelle = ($eval === null);

        if ($estNouvelle) {
            $eval = new EvaluationMatch();
            $eval->setJoueur($joueur);
            $eval->setRencontre($rencontre);
        }

        $agg = $this->agreger($joueur, $rencontre);

        $eval->setIsStarter($agg['isStarter']);
        $eval->setMinutesJouees($agg['minutesJouees']);
        $eval->setTirs2ptsReussis($agg['tirs2ptsReussis']);
        $eval->setTirs2ptsTentes($agg['tirs2ptsTentes']);
        $eval->setTirs3ptsReussis($agg['tirs3ptsReussis']);
        $eval->setTirs3ptsTentes($agg['tirs3ptsTentes']);
        $eval->setLancersReussis($agg['lancersReussis']);
        $eval->setLancersTentes($agg['lancersTentes']);
        $eval->setRebondsOffensifs($agg['rebondsOffensifs']);
        $eval->setRebondsDefensifs($agg['rebondsDefensifs']);
        $eval->setPassesDecisives($agg['passesDecisives']);
        $eval->setInterceptions($agg['interceptions']);
        $eval->setContres($agg['contres']);
        $eval->setContresSubis($agg['contresSubis']);
        $eval->setFautesCommises($agg['fautesCommises']);
        $eval->setFautesProvoquees($agg['fautesProvoquees']);
        $eval->setPertesBalle($agg['pertesBalle']);

        if ($estNouvelle) {
            $this->em->persist($eval);
        }

        return $eval;
    }

    /**
     * Régénère les EvaluationMatch pour TOUTES les joueuses d'une rencontre.
     *
     * Utilisé typiquement à la fin du match (clic "Terminer le match") pour
     * figer les stats agrégées prêtes à l'export PDF.
     *
     * @return EvaluationMatch[] Tableau des évals générées
     */
    public function regenererToutesPourRencontre(Rencontre $rencontre): array
    {
        // Récupère les joueuses qui ont au moins une ActionMatch sur ce match
        // (pas toutes les joueuses du club, juste celles qui ont joué)
        $joueurs = $this->em->createQueryBuilder()
            ->select('DISTINCT j')
            ->from(Joueur::class, 'j')
            ->join(ActionMatch::class, 'a', 'WITH', 'a.joueur = j')
            ->where('a.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->getQuery()
            ->getResult();

        $evals = [];
        foreach ($joueurs as $joueur) {
            $evals[] = $this->genererEvaluationMatch($joueur, $rencontre);
        }

        $this->em->flush();
        return $evals;
    }

    // ====================================================================
    // PRIVATE — helpers de calcul temps
    // ====================================================================

    /**
     * Calcule les minutes jouées en parcourant les paires ENTREE/SORTIE.
     *
     * V1 PRAGMATIQUE :
     *   - Parcourt les actions chronologiquement
     *   - À chaque ENTREE : démarre un chrono
     *   - À chaque SORTIE : ajoute la durée au total
     *   - Si SORTIE sans ENTREE préalable → on ignore (donnée corrompue)
     *   - Si match termine sans SORTIE → on considère que la joueuse a fini sur le terrain
     *     (durée = fin du match - dernière ENTREE)
     *
     * V2 (futur) :
     *   - Gérer les exclusions (5 fautes = sortie forcée)
     *   - Gérer les time-outs (ils ne décomptent pas du temps de jeu)
     *
     * Pour la V1 ON SIMPLIFIE : on prend le nombre de quart-temps distincts
     * où la joueuse a des actions × 10min. Approximation acceptable au début.
     */
    private function calculerMinutesJouees(Joueur $joueur, Rencontre $rencontre): int
    {
        $actions = $this->actionMatchRepository->actionsJoueurSurRencontre($joueur, $rencontre);
        if (empty($actions)) {
            return 0;
        }

        // V1 simple : compte les quart-temps DISTINCTS où la joueuse a des actions
        // × 10 min/QT. Plus précis qu'un compte raw, moins compliqué que ENTREE/SORTIE
        $quartsActifs = [];
        foreach ($actions as $a) {
            $quartsActifs[$a->getQuartTemps()] = true;
        }

        // Quart régulier = 10 min, prolongation = 5 min
        $total = 0;
        foreach (array_keys($quartsActifs) as $qt) {
            $total += in_array($qt, [ActionMatch::EXT_1, ActionMatch::EXT_2], true) ? 5 : 10;
        }

        // Max 40 min régulier + prolongations — sanity check
        return min($total, 50);
    }

    /**
     * Détecte si une joueuse est titulaire (présente dans le QT1).
     *
     * Approche simple : si la joueuse a au moins une action dans le QT1, on
     * considère qu'elle est titulaire. À affiner en Phase 2 avec la gestion
     * explicite des ENTREE à 0:00.
     */
    private function detecterTitulaire(Joueur $joueur, Rencontre $rencontre): bool
    {
        return $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ActionMatch::class, 'a')
            ->where('a.joueur = :joueur')
            ->andWhere('a.rencontre = :rencontre')
            ->andWhere('a.quartTemps = :qt1')
            ->setParameter('joueur', $joueur)
            ->setParameter('rencontre', $rencontre)
            ->setParameter('qt1', ActionMatch::QT_1)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
