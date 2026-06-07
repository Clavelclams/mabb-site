<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\Club;
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
 * ManagerUtilisateursController — gestion des rôles des membres du club.
 *
 * Accessible CLUB_ADMIN (DIRIGEANT) uniquement.
 *
 * Workflow :
 *   1. Dirigeant va sur /utilisateurs
 *   2. Voit la liste des membres actifs du club, groupés par user (un user peut
 *      avoir plusieurs rôles dans le même club ex. COACH + PARENT)
 *   3. Peut : ajouter un rôle, modifier un rôle existant, retirer un rôle
 *
 * SÉCURITÉ ANTI-BRICKING :
 *   Un dirigeant ne peut pas retirer/dégrader le DERNIER DIRIGEANT actif du club
 *   (y compris lui-même). Sinon plus personne ne peut administrer.
 *
 * AUDIT :
 *   Chaque modification de rôle est loggée avec : qui, quoi, quand.
 *   Permet de retracer les changements en cas de litige (qui a promu X ?).
 */
class ManagerUtilisateursController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste des membres actifs du club, avec leurs rôles modifiables.
     *
     *   GET manager.mabb.fr/utilisateurs
     */
    #[Route('/utilisateurs', name: 'manager_utilisateurs_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $club);

        // Récupère tous les UserClubRole ACTIFS du club, ordonnés par nom user
        $ucrs = $this->em->getRepository(UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->join('ucr.user', 'u')
            ->where('ucr.club = :club')
            ->andWhere('ucr.status = :active')
            ->setParameter('club', $club)
            ->setParameter('active', UserClubRole::STATUS_ACTIVE)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();

        // Regrouper par user pour affichage compact (1 ligne par user, ses rôles à côté)
        // Évite N+1 et permet de gérer les multi-rôles d'un même user
        $byUser = [];
        foreach ($ucrs as $ucr) {
            $userId = $ucr->getUser()?->getId();
            if ($userId === null) continue;
            if (!isset($byUser[$userId])) {
                $byUser[$userId] = [
                    'user'  => $ucr->getUser(),
                    'roles' => [],
                ];
            }
            $byUser[$userId]['roles'][] = $ucr;
        }

        // Recherche optionnelle (?q=Jean)
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $qLower = mb_strtolower($q);
            $byUser = array_filter($byUser, function ($row) use ($qLower) {
                $user = $row['user'];
                $hay = mb_strtolower(($user->getPrenom() ?? '') . ' ' . ($user->getNom() ?? '') . ' ' . ($user->getEmail() ?? ''));
                return str_contains($hay, $qLower);
            });
        }

        return $this->render('manager/utilisateurs/index.html.twig', [
            'club'             => $club,
            'membres_par_user' => $byUser,
            'roles_disponibles' => UserClubRole::ROLES_DISPONIBLES,
            'recherche'        => $q,
        ]);
    }

    /**
     * Ajouter un nouveau rôle à un user (en plus de ses rôles existants).
     *
     *   POST manager.mabb.fr/utilisateurs/{userId}/role/ajouter
     */
    #[Route('/utilisateurs/{userId}/role/ajouter', name: 'manager_utilisateurs_role_ajouter', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function ajouterRole(Request $request, int $userId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('ajouter_role_' . $userId, $token)) {
            $this->addFlash('error', '⚠️ Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $cible = $this->em->getRepository(User::class)->find($userId);
        if (!$cible instanceof User) {
            $this->addFlash('error', '❌ Utilisateur introuvable.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $nouveauRole = (string) $request->request->get('role', '');
        if (!UserClubRole::isValidRole($nouveauRole)) {
            $this->addFlash('error', '❌ Rôle invalide.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        // Vérif : ce user a-t-il DÉJÀ ce rôle dans ce club ? (contrainte unique BDD)
        $existant = $this->em->getRepository(UserClubRole::class)->findOneBy([
            'user' => $cible,
            'club' => $club,
            'role' => $nouveauRole,
        ]);

        if ($existant !== null) {
            // S'il existe en status REJECTED, on le réactive (réintégration)
            if ($existant->getStatus() === UserClubRole::STATUS_REJECTED) {
                $existant->setStatus(UserClubRole::STATUS_ACTIVE);
                $existant->setValideParUser($this->getUser() instanceof User ? $this->getUser() : null);
                $existant->setValideAt(new \DateTimeImmutable());
                $this->em->flush();
                $this->logAction('reactivate', $existant);
                $this->addFlash('success', sprintf('✅ Rôle %s réactivé pour %s.', $nouveauRole, $cible->getPrenom()));
            } else {
                $this->addFlash('info', sprintf('ℹ️ %s a déjà le rôle %s (status: %s).', $cible->getPrenom(), $nouveauRole, $existant->getStatus()));
            }
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        // Création d'un nouveau UCR actif (validation directe — c'est un admin qui crée)
        $ucr = new UserClubRole();
        $ucr->setUser($cible);
        $ucr->setClub($club);
        $ucr->setRole($nouveauRole);
        $ucr->setStatus(UserClubRole::STATUS_ACTIVE);
        $ucr->setValideParUser($this->getUser() instanceof User ? $this->getUser() : null);
        $ucr->setValideAt(new \DateTimeImmutable());

        $this->em->persist($ucr);
        $this->em->flush();

        $this->logAction('create', $ucr);
        $this->addFlash('success', sprintf('✅ Rôle %s ajouté à %s.', $nouveauRole, $cible->getPrenom()));
        return $this->redirectToRoute('manager_utilisateurs_index');
    }

    /**
     * Modifier le rôle d'un UCR existant (ex: passer COACH → STAFF).
     *
     *   POST manager.mabb.fr/utilisateurs/role/{ucrId}/modifier
     */
    #[Route('/utilisateurs/role/{ucrId}/modifier', name: 'manager_utilisateurs_role_modifier', methods: ['POST'], requirements: ['ucrId' => '\d+'])]
    public function modifierRole(Request $request, int $ucrId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('modifier_role_' . $ucrId, $token)) {
            $this->addFlash('error', '⚠️ Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $ucr = $this->em->getRepository(UserClubRole::class)->find($ucrId);
        if (!$ucr instanceof UserClubRole || $ucr->getClub()?->getId() !== $club->getId()) {
            $this->addFlash('error', '❌ Rôle introuvable ou hors club.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $nouveauRole = (string) $request->request->get('role', '');
        if (!UserClubRole::isValidRole($nouveauRole)) {
            $this->addFlash('error', '❌ Rôle invalide.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $ancienRole = $ucr->getRole();
        if ($ancienRole === $nouveauRole) {
            $this->addFlash('info', 'ℹ️ Aucun changement (même rôle).');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        // === ANTI-BRICKING : si on dégrade un DIRIGEANT vers autre chose, vérifier
        // qu'il reste au moins UN autre dirigeant actif dans le club ===
        if ($ancienRole === UserClubRole::ROLE_DIRIGEANT
            && $nouveauRole !== UserClubRole::ROLE_DIRIGEANT
            && $this->compterDirigeantsActifs($club, $ucr->getId()) === 0) {
            $this->addFlash('error', '🚫 Impossible : ce serait le dernier dirigeant du club. Promeus quelqu\'un d\'autre DIRIGEANT avant.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $ucr->setRole($nouveauRole);
        $this->em->flush();

        $this->logAction('update', $ucr, ['ancien_role' => $ancienRole]);
        $this->addFlash('success', sprintf('✅ Rôle %s → %s pour %s.', $ancienRole, $nouveauRole, $ucr->getUser()?->getPrenom() ?? '?'));
        return $this->redirectToRoute('manager_utilisateurs_index');
    }

    /**
     * Désactiver un rôle (le user perd ce rôle dans le club).
     *
     *   POST manager.mabb.fr/utilisateurs/role/{ucrId}/desactiver
     */
    #[Route('/utilisateurs/role/{ucrId}/desactiver', name: 'manager_utilisateurs_role_desactiver', methods: ['POST'], requirements: ['ucrId' => '\d+'])]
    public function desactiverRole(Request $request, int $ucrId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('desactiver_role_' . $ucrId, $token)) {
            $this->addFlash('error', '⚠️ Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $ucr = $this->em->getRepository(UserClubRole::class)->find($ucrId);
        if (!$ucr instanceof UserClubRole || $ucr->getClub()?->getId() !== $club->getId()) {
            $this->addFlash('error', '❌ Rôle introuvable ou hors club.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        // === ANTI-BRICKING : si on retire un DIRIGEANT, vérifier qu'il en reste un autre ===
        if ($ucr->getRole() === UserClubRole::ROLE_DIRIGEANT
            && $this->compterDirigeantsActifs($club, $ucr->getId()) === 0) {
            $this->addFlash('error', '🚫 Impossible : ce serait le dernier dirigeant du club.');
            return $this->redirectToRoute('manager_utilisateurs_index');
        }

        $ucr->setStatus(UserClubRole::STATUS_REJECTED);
        $ucr->setValideParUser($this->getUser() instanceof User ? $this->getUser() : null);
        $ucr->setValideAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logAction('deactivate', $ucr);
        $this->addFlash('success', sprintf('✅ Rôle %s retiré à %s.', $ucr->getRole(), $ucr->getUser()?->getPrenom() ?? '?'));
        return $this->redirectToRoute('manager_utilisateurs_index');
    }

    // ====================================================================
    // HELPERS PRIVÉS
    // ====================================================================

    /**
     * Compte le nombre de dirigeants ACTIFS dans un club, en EXCLUANT un UCR donné
     * (utile pour anti-bricking : "si je retire celui-ci, en reste-t-il ?")
     */
    private function compterDirigeantsActifs(Club $club, int $excludeUcrId): int
    {
        return (int) $this->em->getRepository(UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->select('COUNT(ucr.id)')
            ->where('ucr.club = :club')
            ->andWhere('ucr.role = :role')
            ->andWhere('ucr.status = :status')
            ->andWhere('ucr.id != :excludeId')
            ->setParameter('club', $club)
            ->setParameter('role', UserClubRole::ROLE_DIRIGEANT)
            ->setParameter('status', UserClubRole::STATUS_ACTIVE)
            ->setParameter('excludeId', $excludeUcrId)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Audit trail : logue chaque action de modification de rôle.
     * Format structuré pour pouvoir filtrer/analyser plus tard dans le log Symfony.
     */
    private function logAction(string $action, UserClubRole $ucr, array $extra = []): void
    {
        $this->logger->info("UCR action: $action", array_merge([
            'action'      => $action,
            'ucr_id'      => $ucr->getId(),
            'role'        => $ucr->getRole(),
            'status'      => $ucr->getStatus(),
            'user_target' => $ucr->getUser()?->getUserIdentifier(),
            'club'        => $ucr->getClub()?->getNom(),
            'admin'       => $this->getUser()?->getUserIdentifier(),
        ], $extra));
    }
}
