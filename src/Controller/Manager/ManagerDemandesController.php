<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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
        // ====================================================================
        // BLINDAGE COMPLET — chaque étape logue + flash explicite si erreur
        // pour que l'utilisateur sache pourquoi rien ne s'est passé.
        // L'ancienne version pouvait planter silencieusement en cas d'edge case
        // (User supprimé, getUser() null, etc.) → l'utilisateur voyait "rien".
        // ====================================================================

        try {
            $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $ucr->getClub());

            // === Vérif CSRF ===
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('valider_demande_' . $ucr->getId(), $token)) {
                $this->logger->warning('Validation demande : jeton CSRF invalide', [
                    'ucr_id' => $ucr->getId(),
                    'user_admin' => $this->getUser()?->getUserIdentifier(),
                ]);
                $this->addFlash('error', '⚠️ Jeton de sécurité invalide. Rafraîchis la page (F5) et réessaie.');
                return $this->redirectToRoute('manager_demandes_index');
            }

            // === Idempotence : ne pas double-valider ===
            if (!$ucr->isPending()) {
                $this->addFlash('info', 'ℹ️ Cette demande a déjà été traitée.');
                return $this->redirectToRoute('manager_demandes_index');
            }

            // === Rôle final (optionnel — sinon roleDemande utilisé) ===
            $roleFinal = (string) $request->request->get('role_final', '') ?: null;
            if ($roleFinal !== null && !UserClubRole::isValidRole($roleFinal)) {
                $roleFinal = null;
            }

            // === Validation métier ===
            $admin = $this->getUser();
            $ucr->valider($admin instanceof User ? $admin : null, $roleFinal);
            $this->em->flush();

            // === Construction du message flash — robuste si user supprimé ===
            // (cas edge où le User a été supprimé entre l'affichage et le clic)
            $userValide = $ucr->getUser();
            $prenom = $userValide?->getPrenom() ?? 'Personne';
            $nom    = $userValide?->getNom() ?? '';
            $role   = $ucr->getRole() ?? 'rôle non défini';

            $this->logger->info('Demande validée', [
                'ucr_id' => $ucr->getId(),
                'user_target' => $userValide?->getUserIdentifier(),
                'role_final' => $role,
                'admin' => $admin?->getUserIdentifier(),
            ]);

            $this->addFlash('success', sprintf(
                '✅ %s %s validé(e) — rôle : %s.',
                $prenom, $nom, $role
            ));

            return $this->redirectToRoute('manager_demandes_index');

        } catch (\Throwable $e) {
            // === Filet de sécurité ultime — on logue et on affiche un message clair ===
            // Sans ce catch, une exception PHP plantait silencieusement (page blanche
            // ou erreur 500 sans feedback) → l'utilisateur ne savait pas pourquoi.
            $this->logger->error('Validation demande — exception non gérée', [
                'ucr_id' => $ucr->getId(),
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->addFlash('error', sprintf(
                '❌ Erreur lors de la validation. Détail technique : %s — Vérifie var/log/prod.log',
                $e->getMessage()
            ));

            return $this->redirectToRoute('manager_demandes_index');
        }
    }

    /**
     * Rejette une demande : status pending → rejected.
     */
    #[Route('/demandes/{id}/rejeter', name: 'manager_demandes_rejeter', methods: ['POST'])]
    public function rejeter(Request $request, UserClubRole $ucr): Response
    {
        try {
            $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $ucr->getClub());

            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('rejeter_demande_' . $ucr->getId(), $token)) {
                $this->logger->warning('Rejet demande : jeton CSRF invalide', [
                    'ucr_id' => $ucr->getId(),
                ]);
                $this->addFlash('error', '⚠️ Jeton de sécurité invalide. Rafraîchis la page (F5) et réessaie.');
                return $this->redirectToRoute('manager_demandes_index');
            }
            if (!$ucr->isPending()) {
                $this->addFlash('info', 'ℹ️ Cette demande a déjà été traitée.');
                return $this->redirectToRoute('manager_demandes_index');
            }

            $admin = $this->getUser();
            $ucr->rejeter($admin instanceof User ? $admin : null);
            $this->em->flush();

            $userRejete = $ucr->getUser();
            $prenom = $userRejete?->getPrenom() ?? 'Personne';
            $nom    = $userRejete?->getNom() ?? '';

            $this->logger->info('Demande rejetée', [
                'ucr_id' => $ucr->getId(),
                'user_target' => $userRejete?->getUserIdentifier(),
                'admin' => $admin?->getUserIdentifier(),
            ]);

            $this->addFlash('warning', sprintf('🚫 Demande de %s %s rejetée.', $prenom, $nom));
            return $this->redirectToRoute('manager_demandes_index');

        } catch (\Throwable $e) {
            $this->logger->error('Rejet demande — exception non gérée', [
                'ucr_id' => $ucr->getId(),
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->addFlash('error', sprintf(
                '❌ Erreur lors du rejet. Détail : %s — Vérifie var/log/prod.log',
                $e->getMessage()
            ));
            return $this->redirectToRoute('manager_demandes_index');
        }
    }
}
