<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\SeanceSolo;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\SeanceSoloRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Validation en masse des séances solo déclarées par les joueuses.
 *
 * UX coach : voir les déclarations en attente → valider tout d'un coup (ou refuser).
 * Pas de détail individuel de séance — c'est du bulk.
 *
 * Routes :
 *   GET  /seances-solo/         → liste pending + historique
 *   POST /seances-solo/valider  → approuver plusieurs en une fois
 *   POST /seances-solo/{id}/refuser → refuser avec message optionnel
 */
#[Route('/seances-solo', name: 'manager_seances_solo_')]
class ManagerSeancesSoloController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly SeanceSoloRepository $soloRepo,
        private readonly CoachEquipeRepository $coachEquipeRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des séances solo en attente + historique récent.
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        /** @var User $user */
        $user    = $this->getUser();
        $isAdmin = $this->isGranted(ClubVoter::CLUB_ADMIN, $club);

        if ($isAdmin) {
            // Dirigeant → toutes les équipes du club
            $coachEquipes = $this->coachEquipeRepo->createQueryBuilder('ce')
                ->join('ce.equipe', 'e')
                ->addSelect('e')
                ->where('e.club = :club')
                ->setParameter('club', $club)
                ->getQuery()
                ->getResult();
        } else {
            // Coach → uniquement ses équipes
            $coachEquipes = $this->coachEquipeRepo->findByCoach($user);
        }

        $equipes = array_values(array_filter(array_unique(
            array_map(fn($ce) => $ce->getEquipe(), $coachEquipes),
            SORT_REGULAR
        )));

        $pending    = $this->soloRepo->findPendingParEquipes($equipes);
        $historique = $this->soloRepo->findToutesParEquipes($equipes, 50);

        return $this->render('manager/seances_solo/index.html.twig', [
            'club'       => $club,
            'pending'    => $pending,
            'historique' => $historique,
        ]);
    }

    /**
     * Validation en masse — approuver plusieurs séances solo en une seule requête.
     *
     * POST /seances-solo/valider
     * Body: ids[] = [1, 3, 7, ...]
     */
    #[Route('/valider', name: 'valider_masse', methods: ['POST'])]
    public function validerMasse(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_seances_solo_index');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('valider_solos_masse', $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_seances_solo_index');
        }

        /** @var User $user */
        $user  = $this->getUser();
        $ids   = array_map('intval', (array) $request->request->all('ids'));
        $count = 0;

        foreach ($ids as $id) {
            $solo = $this->soloRepo->find($id);
            if (!$solo || $solo->getStatut() !== SeanceSolo::STATUT_PENDING) {
                continue;
            }

            // Vérification multi-tenant : la joueuse doit appartenir au club
            if ($solo->getJoueur()->getClub()->getId() !== $club->getId()) {
                continue;
            }

            $solo->approuver($user);
            $count++;
        }

        $this->em->flush();

        $this->addFlash(
            'success',
            $count > 0
                ? sprintf('%d séance(s) solo validée(s).', $count)
                : 'Aucune séance sélectionnée.'
        );

        return $this->redirectToRoute('manager_seances_solo_index');
    }

    /**
     * Refuser une séance solo — avec message optionnel pour la joueuse.
     *
     * POST /seances-solo/{id}/refuser
     */
    #[Route('/{id}/refuser', name: 'refuser', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refuser(Request $request, SeanceSolo $solo): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_seances_solo_index');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        // Vérification multi-tenant
        if ($solo->getJoueur()->getClub()->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('refuser_solo_' . $solo->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_seances_solo_index');
        }

        if ($solo->getStatut() !== SeanceSolo::STATUT_PENDING) {
            $this->addFlash('warning', 'Cette séance n\'est plus en attente.');
            return $this->redirectToRoute('manager_seances_solo_index');
        }

        /** @var User $user */
        $user    = $this->getUser();
        $message = trim((string) $request->request->get('message_coach', ''));
        $solo->refuser($user, $message ?: null);

        $this->em->flush();

        $this->addFlash('info', 'Séance solo refusée.');
        return $this->redirectToRoute('manager_seances_solo_index');
    }
}
