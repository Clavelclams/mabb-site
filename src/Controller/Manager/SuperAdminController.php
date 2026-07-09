<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\Club;
use App\Repository\Core\ClubRepository;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SuperAdminController — panneau transverse RÉSERVÉ à ROLE_SUPER_ADMIN
 * (ex. admin@velito.fr). Voir TOUS les clubs de la plateforme et « entrer »
 * dans n'importe lequel pour dépanner, sans en être membre.
 *
 * Mécanique :
 *   - TenantResolver autorise le super-admin à poser n'importe quel club actif
 *     (bypass de userBelongsToClub).
 *   - ClubVoter court-circuite déjà ROLE_SUPER_ADMIN → tous les droits une fois
 *     dans le club.
 * Ici on ne fait donc que LISTER + poser le club actif en session.
 *
 * ⚠️ Accès cross-club = pouvoir sensible : réservé au seul rôle global
 * ROLE_SUPER_ADMIN (aucun rôle de club ne l'accorde).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class SuperAdminController extends AbstractController
{
    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly TenantResolver $tenantResolver,
    ) {}

    #[Route('/super-admin/clubs', name: 'manager_super_admin_clubs', methods: ['GET'])]
    public function clubs(): Response
    {
        return $this->render('manager/super_admin/clubs.html.twig', [
            'clubs'      => $this->clubRepository->findBy([], ['nom' => 'ASC']),
            'club_actif' => $this->tenantResolver->getCurrentClub(),
        ]);
    }

    #[Route('/super-admin/clubs/{id}/entrer', name: 'manager_super_admin_entrer', methods: ['POST'])]
    public function entrer(Club $club, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('super_admin_entrer_' . $club->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_super_admin_clubs');
        }

        // Autorisé pour un super-admin même sans appartenance (cf. TenantResolver).
        $this->tenantResolver->setCurrentClub($club);
        $this->addFlash('success', 'Mode support : tu es entré dans le club « ' . $club->getNom() . ' ».');

        return $this->redirectToRoute('manager_dashboard');
    }
}
