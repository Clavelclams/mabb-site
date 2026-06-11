<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Sport\Joueur;
use App\Repository\Sport\EvaluationMatchRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * B10 — Agrégation des stats personnelles d'un joueur sur une saison.
 *
 * Source : table `evaluation_match` (alimentée par le coach après chaque
 * match). Quand B11 sera connecté avec SessionStatsLive promues, le service
 * pourra fusionner les 2 sources.
 *
 * Retourne :
 *   - totaux saison (points, rebonds, passes, etc.)
 *   - moyennes par match
 *   - pourcentages tirs
 *   - dernières N évaluations (pour graph progression)
 */
class JoueurStatsAggregator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationMatchRepository $evalRepo,
    ) {}

    public function statsSaison(Joueur $joueur, ?string $saison = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')
            ->from(\App\Entity\Sport\EvaluationMatch::class, 'e')
            ->leftJoin('e.rencontre', 'r')->addSelect('r')
            ->where('e.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('r.date', 'DESC');

        // TODO : si on a une entité Saison (B6), filtrer ici par saison
        // Pour V1 : on prend tout
        $evals = $qb->getQuery()->getResult();

        return $this->compute($evals);
    }

    /** @param \App\Entity\Sport\EvaluationMatch[] $evals */
    private function compute(array $evals): array
    {
        $nbMatchs = count($evals);
        if ($nbMatchs === 0) {
            return $this->empty();
        }

        $totals = [
            'minutes'    => 0,
            'points'     => 0,
            'tirs2r'     => 0, 'tirs2t' => 0,
            'tirs3r'     => 0, 'tirs3t' => 0,
            'lfr'        => 0, 'lft' => 0,
            'reb_o'      => 0, 'reb_d' => 0,
            'passes'     => 0,
            'inter'      => 0,
            'contres'    => 0,
            'fautes'     => 0,
            'pertes'     => 0,
            'eval_total' => 0,
        ];

        $progression = []; // pour graph Chart.js (10 derniers matchs)
        $titularisations = 0;

        foreach ($evals as $e) {
            $totals['minutes']  += $e->getMinutesJouees();
            $totals['points']   += $e->getPoints();
            $totals['tirs2r']   += $e->getTirs2ptsReussis();
            $totals['tirs2t']   += $e->getTirs2ptsTentes();
            $totals['tirs3r']   += $e->getTirs3ptsReussis();
            $totals['tirs3t']   += $e->getTirs3ptsTentes();
            $totals['lfr']      += $e->getLancersReussis();
            $totals['lft']      += $e->getLancersTentes();
            $totals['reb_o']    += $e->getRebondsOffensifs();
            $totals['reb_d']    += $e->getRebondsDefensifs();
            $totals['passes']   += $e->getPassesDecisives();
            $totals['inter']    += $e->getInterceptions();
            $totals['contres']  += $e->getContres();
            $totals['fautes']   += $e->getFautesCommises();
            $totals['pertes']   += $e->getPertesBalle();
            $totals['eval_total'] += $e->getEval();
            if ($e->isStarter()) {
                $titularisations++;
            }
        }

        // 10 derniers matchs pour graph
        $progression = array_slice($evals, 0, 10);
        $progression = array_reverse($progression); // chronologique pour Chart.js
        $graphData = array_map(static fn(\App\Entity\Sport\EvaluationMatch $e) => [
            'date'       => $e->getRencontre()?->getDate()?->format('d/m'),
            'points'     => $e->getPoints(),
            'eval'       => $e->getEval(),
            'rebonds'    => $e->getRebonds(),
        ], $progression);

        return [
            'nb_matchs'    => $nbMatchs,
            'titulaire'    => $titularisations,
            'moyennes' => [
                'points'   => round($totals['points'] / $nbMatchs, 1),
                'rebonds'  => round(($totals['reb_o'] + $totals['reb_d']) / $nbMatchs, 1),
                'passes'   => round($totals['passes'] / $nbMatchs, 1),
                'minutes'  => round($totals['minutes'] / $nbMatchs, 1),
                'eval'     => round($totals['eval_total'] / $nbMatchs, 1),
            ],
            'totaux'   => [
                'points'  => $totals['points'],
                'reb_off' => $totals['reb_o'],
                'reb_def' => $totals['reb_d'],
                'passes'  => $totals['passes'],
                'inter'   => $totals['inter'],
                'contres' => $totals['contres'],
                'fautes'  => $totals['fautes'],
                'pertes'  => $totals['pertes'],
            ],
            'pourcentages' => [
                'tirs2'  => $totals['tirs2t'] > 0 ? round(($totals['tirs2r'] / $totals['tirs2t']) * 100, 1) : null,
                'tirs3'  => $totals['tirs3t'] > 0 ? round(($totals['tirs3r'] / $totals['tirs3t']) * 100, 1) : null,
                'lf'     => $totals['lft']    > 0 ? round(($totals['lfr']   / $totals['lft']) * 100, 1) : null,
            ],
            'tentatives' => [
                'tirs2'  => $totals['tirs2t'],
                'tirs3'  => $totals['tirs3t'],
                'lf'     => $totals['lft'],
            ],
            'reussites' => [
                'tirs2'  => $totals['tirs2r'],
                'tirs3'  => $totals['tirs3r'],
                'lf'     => $totals['lfr'],
            ],
            'graph_progression' => $graphData,
            'evaluations'       => $evals, // pour liste détaillée
        ];
    }

    private function empty(): array
    {
        return [
            'nb_matchs'   => 0,
            'titulaire'   => 0,
            'moyennes'    => ['points' => 0, 'rebonds' => 0, 'passes' => 0, 'minutes' => 0, 'eval' => 0],
            'totaux'      => ['points' => 0, 'reb_off' => 0, 'reb_def' => 0, 'passes' => 0, 'inter' => 0, 'contres' => 0, 'fautes' => 0, 'pertes' => 0],
            'pourcentages' => ['tirs2' => null, 'tirs3' => null, 'lf' => null],
            'tentatives'  => ['tirs2' => 0, 'tirs3' => 0, 'lf' => 0],
            'reussites'   => ['tirs2' => 0, 'tirs3' => 0, 'lf' => 0],
            'graph_progression' => [],
            'evaluations' => [],
        ];
    }

    /**
     * Récupère l'évaluation détaillée d'un joueur pour une rencontre précise.
     */
    public function evalForMatch(Joueur $joueur, int $rencontreId): ?\App\Entity\Sport\EvaluationMatch
    {
        return $this->em->createQueryBuilder()
            ->select('e')
            ->from(\App\Entity\Sport\EvaluationMatch::class, 'e')
            ->where('e.joueur = :j')
            ->andWhere('IDENTITY(e.rencontre) = :r')
            ->setParameter('j', $joueur)
            ->setParameter('r', $rencontreId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
