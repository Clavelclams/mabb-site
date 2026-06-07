<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter dédié à la trésorerie d'un club.
 *
 * Pourquoi un voter à PART de ClubVoter ?
 *   - La trésorerie a sa propre logique (rôle TRESORIER + super-admin),
 *     qui n'a rien à voir avec STAFF/COACH/DIRIGEANT.
 *   - Garder ClubVoter focalisé sur "qui appartient au club" et créer
 *     un voter dédié pour les opérations financières = SRP.
 *   - Si demain on raffine les droits trésorerie (ex: lecture seule pour
 *     un dirigeant non-trésorier), on modifie ICI sans toucher au reste.
 *
 * RÈGLE D'OR :
 *   - ROLE_SUPER_ADMIN (admin@mabb.fr) → accès complet. Toujours.
 *   - ROLE_TRESORIER dans le club concerné → accès complet.
 *   - Tous les autres → refus.
 *
 * @extends Voter<string, Club|ClubAwareInterface>
 */
class TresorerieVoter extends Voter
{
    /** Voir le dashboard trésorerie + lister les opérations */
    public const CAN_VIEW = 'TRESORERIE_VIEW';
    /** Créer / modifier / supprimer une opération */
    public const CAN_MANAGE = 'TRESORERIE_MANAGE';

    private const SUPPORTED = [self::CAN_VIEW, self::CAN_MANAGE];

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED, true)) {
            return false;
        }
        // Accepte un Club directement OU toute entité du club (ex: OperationTresorerie)
        return $subject instanceof Club
            || $subject instanceof ClubAwareInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Court-circuit : un super-admin a TOUS les droits, sur TOUS les clubs.
        // C'est lui (admin@mabb.fr) qui debug / dépanne en prod si le trésorier
        // d'un club s'absente, etc.
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Extraire le Club du subject
        $club = $subject instanceof Club
            ? $subject
            : $subject->getClub();

        if (!$club instanceof Club) {
            return false;
        }

        // Pour Phase D.1, les deux attributs (VIEW / MANAGE) ont le même test :
        // être TRESORIER actif dans ce club. On séparera si on veut plus tard
        // donner une lecture seule à d'autres rôles.
        return $this->isTresorier($user, $club);
    }

    /**
     * Vérifie si l'user a un UserClubRole TRESORIER actif (isActive + status=active)
     * dans le club donné.
     */
    private function isTresorier(User $user, Club $club): bool
    {
        foreach ($user->getUserClubRoles() as $ucr) {
            if (
                $ucr->getClub()?->getId() === $club->getId()
                && $ucr->getRole() === UserClubRole::ROLE_TRESORIER
                && $ucr->isActive()
                && $ucr->isStatusActive()
            ) {
                return true;
            }
        }
        return false;
    }
}
