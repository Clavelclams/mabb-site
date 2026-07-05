<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\JoueurEquipeRepository;
use App\Repository\Sport\SeanceRepository;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerCoachDashboardController — Tableau de bord personnalisé pour les coaches.
 *
 * Route : GET /coach/dashboard (manager_coach_dashboard)
 *
 * Affiche :
 *   - Les équipes coachées (saison courante)
 *   - Les prochaines séances pour chacune (3 max par équipe)
 *   - Les joueuses de chaque équipe avec leur statut bilan
 *   - Accès rapide : créer un bilan, voir le profil PIRB
 *
 * Multi-tenant : filtre par club actif de la session.
 * Accessible aux coaches ET au staff (CLUB_STAFF_ELARGI).
 */
class ManagerCoachDashboardController extends AbstractController
{
    public function __construct(
        private readonly CoachEquipeRepository    $coachEquipeRepo,
        private readonly JoueurEquipeRepository   $joueurEquipeRepo,
        private readonly SeanceRepository         $seanceRepo,
        private readonly BilanCompetenceRepository $bilanRepo,
        private readonly TenantResolver           $tenantResolver,
        private readonly \App\Service\SaisonService $saisonService,
    ) {}

    #[Route('/coach/dashboard', name: 'manager_coach_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            $this->addFlash('warning', 'Aucun club actif. Sélectionne ton club d\'abord.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF_ELARGI', $club);

        /** @var User $user */
        $user         = $this->getUser();
        $saison       = $this->saisonCourante();
        $coachEquipes = $this->coachEquipeRepo->findByCoach($user, $saison);

        $equipeData = [];
        foreach ($coachEquipes as $ce) {
            $equipe   = $ce->getEquipe();
            if ($equipe === null) continue;

            // Joueuses actives de l'équipe pour cette saison
            // joueusesParEquipeSaison() retourne JoueurEquipe[] — on extrait les Joueur objects
            $joueurEquipes = $this->joueurEquipeRepo->joueusesParEquipeSaison($equipe, $saison);
            $joueurs = array_values(array_filter(
                array_map(static fn($je) => $je->getJoueur(), $joueurEquipes),
                static fn($j) => $j !== null
            ));

            // 3 prochaines séances
            $seances = $this->seanceRepo->findProchaines($equipe, 3);

            // Dernier bilan par joueuse (map: joueur_id → BilanCompetence|null)
            // findLastBilanByJoueurs attend Joueur[] — OK maintenant
            $bilanMap = $this->bilanRepo->findLastBilanByJoueurs($joueurs);

            $equipeData[] = [
                'coach_equipe' => $ce,
                'equipe'       => $equipe,
                'joueurs'      => $joueurs,
                'seances'      => $seances,
                'bilan_map'    => $bilanMap,
            ];
        }

        return $this->render('manager/coach_dashboard/index.html.twig', [
            'equipe_data'   => $equipeData,
            'saison'        => $saison,
            'nb_equipes'    => count($coachEquipes),
        ]);
    }

    /**
     * [V2.4 05/07/2026] Délègue à SaisonService (sélecteur global +
     * bascule auto 1er juillet) — fin de la logique dupliquée.
     */
    private function saisonCourante(): string
    {
        return $this->saisonService->getSaisonActive();
    }
}
