<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerDemandesController — gestion des demandes d'inscription au club.
 *
 * Accessible aux CLUB_ADMIN (dirigeants) uniquement. Liste les UserClubRole
 * pending du club actif et permet de les valider ou rejeter.
 *
 * Workflow :
 *   1. User s'inscrit (signup Manager) ou se logue depuis vitrine (auto-link)
 *      → UserClubRole créé en status=PENDING
 *   2. Dirigeant visite /demandes
 *      → voit la liste des demandes pending
 *   3. Dirigeant clique Valider ou Rejeter
 *      → status passe à active ou rejected
 *      → si validé : rôle final appliqué (depuis roleDemande)
 *
 * Empêche les "voyeurs" qui s'inscriraient pour fouiner dans plusieurs clubs.
 */
class ManagerDemandesController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des demandes pending pour le club actif.
     */
    #[Route('/demandes', name: 'manager_demandes_index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $club);

        // Requête manuelle : on prend les UserClubRole pending du club courant
        $demandes = $this->em->getRepository(UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->andWhere('ucr.club = :club')->setParameter('club', $club)
            ->andWhere('ucr.status = :pending')->setParameter('pending', UserClubRole::STATUS_PENDING)
            ->orderBy('ucr.createdAt', 'ASC')
            ->getQuery()->getResult();

        // Historique : demandes validées/rejetées récentes (50 dernières)
        $historique = $this->em->getRepository(UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->andWhere('ucr.club = :club')->setParameter('club', $club)
            ->andWhere('ucr.status IN (:statuses)')
            ->setParameter('statuses', [UserClubRole::STATUS_ACTIVE, UserClubRole::STATUS_REJECTED])
            ->andWhere('ucr.valideAt IS NOT NULL')
            ->orderBy('ucr.valideAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        return $this->render('manager/demandes/index.html.twig', [
            'club'       => $club,
            'demandes'   => $demandes,
            'historique' => $historique,
        ]);
    }

    /**
     * Valide une demande : status pending → active.
     * Le rôle DEMANDÉ devient le rôle effectif (l'admin peut le surcharger via POST).
     */
    #[Route('/demandes/{id}/valider', name: 'manager_demandes_valider', methods: ['POST'])]
    public function valider(Request $request, UserClubRole $ucr): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $ucr->getClub());

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('valider_demande_' . $ucr->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_demandes_index');
        }
        if (!$ucr->isPending()) {
            $this->addFlash('info', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('manager_demandes_index');
        }

        // Le dirigeant peut surcharger le rôle final si voulu
        $roleFinal = (string) $request->request->get('role_final', '') ?: null;
        if ($roleFinal !== null && !UserClubRole::isValidRole($roleFinal)) {
            $roleFinal = null;  // fallback sur roleDemande
        }

        $admin = $this->getUser();
        $ucr->valider($admin instanceof User ? $admin : null, $roleFinal);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s %s validé(e) — rôle : %s.',
            $ucr->getUser()->getPrenom(),
            $ucr->getUser()->getNom(),
            $ucr->getRole()
        ));
        return $this->redirectToRoute('manager_demandes_index');
    }

    /**
     * Rejette une demande : status pending → rejected.
     */
    #[Route('/demandes/{id}/rejeter', name: 'manager_demandes_rejeter', methods: ['POST'])]
    public function rejeter(Request $request, UserClubRole $ucr): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $ucr->getClub());

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('rejeter_demande_' . $ucr->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_demandes_index');
        }
        if (!$ucr->isPending()) {
            $this->addFlash('info', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('manager_demandes_index');
        }

        $admin = $this->getUser();
        $ucr->rejeter($admin instanceof User ? $admin : null);
        $this->em->flush();

        $this->addFlash('warning', sprintf(
            'Demande de %s %s rejetée.',
            $ucr->getUser()->getPrenom(),
            $ucr->getUser()->getNom()
        ));
        return $this->redirectToRoute('manager_demandes_index');
    }
}
