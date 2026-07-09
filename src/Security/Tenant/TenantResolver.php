<?php

namespace App\Security\Tenant;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * TenantResolver : résout quel club est "actif" pour la session courante.
 *
 * Logique :
 * 1. Si l'user a un club_id en session -> utilise celui-là
 * 2. Sinon, si l'user n'a qu'un seul club -> l'auto-sélectionne
 * 3. Sinon -> retourne null (il faut que l'user choisisse son club)
 *
 * Le club actif est stocké en session sous la clé 'active_club_id'.
 */
class TenantResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClubRepository $clubRepository,
        private readonly Security $security,
    ) {}

    /**
     * Retourne le club actif pour l'utilisateur connecté.
     */
    public function getCurrentClub(): ?Club
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $session = $this->requestStack->getSession();

        // 1. Club déjà sélectionné en session
        $activeClubId = $session->get('active_club_id');
        if ($activeClubId) {
            $club = $this->clubRepository->find($activeClubId);
            // Vérifier que l'user appartient vraiment à ce club (sécurité).
            // EXCEPTION : un ROLE_SUPER_ADMIN (support cross-club, ex. admin@velito.fr)
            // peut entrer dans N'IMPORTE quel club pour dépanner — il n'a pas de
            // UserClubRole dans ces clubs. ClubVoter le court-circuite déjà côté droits.
            if ($club && ($this->isSuperAdmin() || $this->userBelongsToClub($user, $club))) {
                return $club;
            }
            // Si le club en session n'est plus valide, on le supprime
            $session->remove('active_club_id');
        }

        // 2. Auto-sélection si l'user n'a qu'un seul club
        $clubs = $this->getUserClubs($user);
        if (count($clubs) === 1) {
            $club = $clubs[0];
            $session->set('active_club_id', $club->getId());
            return $club;
        }

        // 3. Plusieurs clubs -> l'user doit choisir
        return null;
    }

    /**
     * Force le changement de club actif (ex: menu déroulant multi-clubs).
     */
    public function setCurrentClub(Club $club): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }
        // Un super-admin peut basculer sur n'importe quel club (support cross-club) ;
        // un user normal ne peut basculer que sur un club dont il est membre validé.
        if (!$this->isSuperAdmin() && !$this->userBelongsToClub($user, $club)) {
            throw new \LogicException('L\'utilisateur n\'appartient pas à ce club.');
        }
        $this->requestStack->getSession()->set('active_club_id', $club->getId());
    }

    /**
     * Vrai si l'utilisateur courant a le rôle global ROLE_SUPER_ADMIN.
     * Le super-admin (ex. admin@velito.fr) voit tous les clubs et peut entrer
     * dans n'importe lequel pour dépanner, sans y être membre.
     */
    public function isSuperAdmin(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN');
    }

    /**
     * Retourne tous les clubs actifs de l'utilisateur (UserClubRole status=active).
     * Les UserClubRole pending (en attente de validation par dirigeant) sont
     * exclus — un user pending ne "possède" pas encore son club.
     *
     * @return Club[]
     */
    public function getUserClubs(User $user): array
    {
        $clubs = [];
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->isActive() && $ucr->isStatusActive() && $ucr->getClub()?->isActive()) {
                $club = $ucr->getClub();
                // Dédoublonnage par ID
                $clubs[$club->getId()] = $club;
            }
        }
        return array_values($clubs);
    }

    /**
     * Vérifie si l'user a au moins un UserClubRole actif ET validé dans ce club.
     * Un UserClubRole pending ne compte PAS.
     */
    public function userBelongsToClub(User $user, Club $club): bool
    {
        foreach ($user->getUserClubRoles() as $ucr) {
            if (
                $ucr->getClub()?->getId() === $club->getId()
                && $ucr->isActive()
                && $ucr->isStatusActive()
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Indique si l'user a au moins une demande UserClubRole en attente
     * de validation. Utile pour rediriger vers /en-attente.
     */
    public function hasPendingMembership(User $user): bool
    {
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->isPending()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retourne les rôles métier de l'user dans le club actif (validés).
     *
     * @return string[]  Ex: ['COACH', 'BENEVOLE']
     */
    public function getCurrentUserRoles(): array
    {
        $user = $this->security->getUser();
        $club = $this->getCurrentClub();

        if (!$user instanceof User || !$club) {
            return [];
        }

        $roles = [];
        foreach ($user->getUserClubRoles() as $ucr) {
            if (
                $ucr->getClub()?->getId() === $club->getId()
                && $ucr->isActive()
                && $ucr->isStatusActive()
            ) {
                $roles[] = $ucr->getRole();
            }
        }
        return $roles;
    }

    /**
     * Vérifie si l'user a un rôle métier spécifique dans le club actif.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getCurrentUserRoles(), true);
    }
}
