<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\JoueurRepository;
use App\Service\Stats\JoueurStatsAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B10/B11 — PIRB Stats personnelles.
 *
 * Routes :
 *   GET /stats                  → résumé saison
 *   GET /stats/match/{id}       → détail d'un match
 *
 * Source : EvaluationMatch (saisi par coach).
 * Phase 2 : fusion avec Stats Live promues officielles (B11.2).
 */
class PirbStatsController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly JoueurStatsAggregator $aggregator,
    ) {}

    #[Route('/stats', name: 'pirb_stats', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $stats = $this->aggregator->statsSaison($joueur);

        return $this->render('pirb/stats.html.twig', [
            'joueur' => $joueur,
            'stats'  => $stats,
        ]);
    }

    #[Route('/stats/match/{id}', name: 'pirb_stats_match', methods: ['GET'])]
    public function match(Rencontre $rencontre): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        $eval = $this->aggregator->evalForMatch($joueur, $rencontre->getId());

        return $this->render('pirb/stats_match.html.twig', [
            'joueur'    => $joueur,
            'rencontre' => $rencontre,
            'eval'      => $eval,
        ]);
    }
}
