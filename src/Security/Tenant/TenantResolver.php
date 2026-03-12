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
            // Vérifier que l'user appartient vraiment à ce club (sécurité)
            if ($club && $this->userBelongsToClub($user, $club)) {
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
        if (!$this->userBelongsToClub($user, $club)) {
            throw new \LogicException('L\'utilisateur n\'appartient pas à ce club.');
        }
        $this->requestStack->getSession()->set('active_club_id', $club->getId());
    }

    /**
     * Retourne tous les clubs actifs de l'utilisateur.
     *
     * @return Club[]
     */
    public function getUserClubs(User $user): array
    {
        $clubs = [];
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->isActive() && $ucr->getClub()?->isActive()) {
                $club = $ucr->getClub();
                // Dédoublonnage par ID
                $clubs[$club->getId()] = $club;
            }
        }
        return array_values($clubs);
    }

    /**
     * Vérifie si l'user a au moins un rôle actif dans ce club.
     */
    public function userBelongsToClub(User $user, Club $club): bool
    {
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retourne les rôles métier de l'user dans le club actif.
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
            if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isActive()) {
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
