<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\DemandeAccesPdf;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\DemandeAccesPdfRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [B22a-sec] Gestion des demandes d'accès aux PDFs FFBB côté coach/staff.
 *
 * Contexte : les joueuses ne peuvent pas télécharger directement les PDFs
 * officiels FFBB. Elles soumettent une demande → le coach la valide ici.
 *
 * Accès :
 *   - CLUB_COACH  : voit ses équipes + peut approuver/refuser
 *   - CLUB_STAFF  : voit tout le club + peut approuver/refuser
 *
 * Routes :
 *   GET  /demandes-pdf           → liste des demandes (pending + historique)
 *   POST /demandes-pdf/{id}/approuver
 *   POST /demandes-pdf/{id}/refuser
 */
class ManagerDemandesAccesPdfController extends AbstractController
{
    /** Saison courante — synchroniser avec ManagerStaffController. */
    private const SAISON_COURANTE = '2026-2027';

    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly DemandeAccesPdfRepository $demandeRepo,
        private readonly CoachEquipeRepository $coachEquipeRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des demandes en attente + historique récent (30 dernières).
     */
    #[Route('/demandes-pdf', name: 'manager_demandes_pdf_index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        // Accès : coach ou staff du club
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        /** @var User $user */
        $user = $this->getUser();

        // Les coachs ne voient QUE leurs équipes.
        // Les dirigeants (CLUB_ADMIN) voient toutes les équipes du club.
        // CLUB_STAFF inclut les coachs → on utilise CLUB_ADMIN pour distinguer dirigeant vs coach.
        $isAdmin = $this->isGranted(ClubVoter::CLUB_ADMIN, $club);

        if ($isAdmin) {
            // Toutes les équipes du club (pour les dirigeants)
            $coachEquipes = $this->coachEquipeRepo->createQueryBuilder('ce')
                ->join('ce.equipe', 'e')
                ->addSelect('e')
                ->where('e.club = :club')
                ->setParameter('club', $club)
                ->getQuery()
                ->getResult();
        } else {
            // Uniquement les équipes coachées par ce user (pour les coachs)
            $coachEquipes = $this->coachEquipeRepo->findByCoach($user, self::SAISON_COURANTE);
        }

        $equipes = array_map(fn ($ce) => $ce->getEquipe(), $coachEquipes);
        $equipes = array_filter($equipes); // enlever les null
        $equipes = array_values(array_unique($equipes, SORT_REGULAR));

        $pendingDemandes  = $this->demandeRepo->findPendingParEquipes($equipes);
        $historiqueRecent = $this->demandeRepo->findToutesParEquipes($equipes, 30);

        return $this->render('manager/demandes_acces_pdf/index.html.twig', [
            'club'             => $club,
            'pending_demandes' => $pendingDemandes,
            'historique'       => $historiqueRecent,
            'nb_pending'       => count($pendingDemandes),
            'equipes'          => $equipes,
        ]);
    }

    /**
     * Approuver une demande.
     */
    #[Route('/demandes-pdf/{id}/approuver', name: 'manager_demandes_pdf_approuver', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approuver(DemandeAccesPdf $demande, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        if (!$this->isCsrfTokenValid('demande_pdf_' . $demande->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('manager_demandes_pdf_index');
        }

        // Vérif multi-tenant : la joueuse appartient bien au club courant
        if ($demande->getJoueur()?->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException('Ce document n\'appartient pas à ton club.');
        }

        if (!$demande->isPending()) {
            $this->addFlash('info', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('manager_demandes_pdf_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $message = trim($request->request->get('message_coach', ''));

        $demande->approuver($user, $message ?: null);
        $this->em->flush();

        $joueur = $demande->getJoueur();
        $this->addFlash('success', sprintf(
            'Accès approuvé pour %s — %s (%s).',
            $joueur?->getPrenom() . ' ' . $joueur?->getNom(),
            $demande->getLabelType(),
            $demande->getRencontre()?->getAdversaire() ?? 'match'
        ));

        return $this->redirectToRoute('manager_demandes_pdf_index');
    }

    /**
     * Refuser une demande.
     */
    #[Route('/demandes-pdf/{id}/refuser', name: 'manager_demandes_pdf_refuser', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refuser(DemandeAccesPdf $demande, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        if (!$this->isCsrfTokenValid('demande_pdf_' . $demande->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('manager_demandes_pdf_index');
        }

        // Vérif multi-tenant
        if ($demande->getJoueur()?->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException('Ce document n\'appartient pas à ton club.');
        }

        if (!$demande->isPending()) {
            $this->addFlash('info', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('manager_demandes_pdf_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $message = trim($request->request->get('message_coach', ''));

        $demande->refuser($user, $message ?: null);
        $this->em->flush();

        $joueur = $demande->getJoueur();
        $this->addFlash('warning', sprintf(
            'Accès refusé pour %s — %s.',
            $joueur?->getPrenom() . ' ' . $joueur?->getNom(),
            $demande->getLabelType()
        ));

        return $this->redirectToRoute('manager_demandes_pdf_index');
    }
}
