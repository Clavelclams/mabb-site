<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\SessionStatsLive;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\SessionStatsLiveRepository;
use App\Security\Voter\ClubVoter;
use App\Service\Stats\SessionStatsLivePromoteur;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller dédié à la gestion des sessions Stats Live — V2.1d Étape 2.
 *
 * SÉPARÉ de StatsLiveController parce que :
 *   - StatsLiveController gère la SAISIE (interface live + API actions).
 *   - SessionStatsLiveController gère la GESTION des sessions (lister,
 *     promouvoir officielle, archiver, marquer complète).
 *
 * Séparation des responsabilités côté URL : /stats-live/* pour saisir,
 * /sessions/* pour gérer.
 */
class SessionStatsLiveController extends AbstractController
{
    public function __construct(
        private readonly SessionStatsLiveRepository $sessionRepository,
        private readonly SessionStatsLivePromoteur $promoteur,
        private readonly ActionMatchRepository $actionMatchRepository,
    ) {}

    /**
     * Liste toutes les sessions de saisie d'une rencontre.
     * Visible par tout CLUB_STAFF (coach/dirigeant/staff/super-admin).
     */
    #[Route(
        '/rencontres/{id}/sessions',
        name: 'manager_rencontre_sessions',
        methods: ['GET'],
        requirements: ['id' => '\d+']
    )]
    public function index(Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $sessions = $this->sessionRepository->findByRencontre($rencontre);

        // Compteur d'actions par session (pour l'UI)
        $nbActionsParSession = [];
        foreach ($sessions as $s) {
            $nbActionsParSession[$s->getId()] = (int) $this->actionMatchRepository->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.session = :session')
                ->setParameter('session', $s)
                ->getQuery()
                ->getSingleScalarResult();
        }

        return $this->render('manager/sessions_stats_live/index.html.twig', [
            'rencontre'             => $rencontre,
            'sessions'              => $sessions,
            'nb_actions_par_session' => $nbActionsParSession,
        ]);
    }

    /**
     * Promeut une session en OFFICIELLE.
     * Les autres officielles existantes sont rétrogradées en ARCHIVEE
     * (logique dans le service Promoteur — atomique).
     */
    #[Route(
        '/sessions/{id}/promouvoir-officielle',
        name: 'manager_session_promouvoir',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function promouvoir(SessionStatsLive $session, Request $request): Response
    {
        $rencontre = $session->getRencontre();
        if ($rencontre === null) {
            throw $this->createNotFoundException('Session orpheline.');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('promouvoir_session_' . $session->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
        }

        $promoteur = $this->getUser();
        if (!$promoteur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->promoteur->promouvoirOfficielle($session, $promoteur);
            $this->addFlash('success', sprintf(
                'Session « %s » promue officielle. Elle alimente désormais les stats de la fiche joueuse.',
                $session->getNom()
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
    }

    /**
     * Archive une session (annulation).
     */
    #[Route(
        '/sessions/{id}/archiver',
        name: 'manager_session_archiver',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function archiver(SessionStatsLive $session, Request $request): Response
    {
        $rencontre = $session->getRencontre();
        if ($rencontre === null) {
            throw $this->createNotFoundException('Session orpheline.');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('archiver_session_' . $session->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
        }

        try {
            $this->promoteur->archiver($session);
            $this->addFlash('success', sprintf('Session « %s » archivée.', $session->getNom()));
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
    }

    /**
     * Marque une session COMPLETE (le user a fini sa saisie).
     * Permis uniquement au user qui a créé la session (ou à un staff).
     */
    #[Route(
        '/sessions/{id}/marquer-complete',
        name: 'manager_session_marquer_complete',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function marquerComplete(SessionStatsLive $session, Request $request): Response
    {
        $rencontre = $session->getRencontre();
        if ($rencontre === null) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Peut être marqué complète par le créateur OU par le staff
        $estCreateur = $session->getCreatedBy()?->getId() === $user->getId();
        $estStaff = $this->isGranted(ClubVoter::CLUB_STAFF, $rencontre);
        if (!$estCreateur && !$estStaff) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('complete_session_' . $session->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
        }

        try {
            $this->promoteur->marquerComplete($session);
            $this->addFlash('success', 'Session marquée comme terminée. Un staff peut maintenant la promouvoir officielle.');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('manager_rencontre_sessions', ['id' => $rencontre->getId()]);
    }
}
