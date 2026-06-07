<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\NoteFrais;
use App\Security\Tenant\TenantResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter dédié aux notes de frais — Bureau Phase D.2.
 *
 * RÈGLES :
 *   - SUBMIT : tout membre actif du club peut déposer une note.
 *     (un parent ou un joueur peut aussi déposer si la situation s'y prête —
 *     ex: un parent qui a avancé pour son enfant. On ne discrimine pas.)
 *   - VIEW : le demandeur peut voir SES notes + trésorier/super-admin voit tout.
 *   - VALIDATE : trésorier ou super-admin uniquement.
 *   - DELETE : uniquement le demandeur ET uniquement si la note est encore
 *     EN_ATTENTE (verrouillage post-validation).
 *
 * Notes acceptées comme subject :
 *   - Un Club (pour vérifier "est-ce que je peux SOUMETTRE dans ce club")
 *   - Une NoteFrais (pour les autres actions)
 *
 * @extends Voter<string, Club|NoteFrais>
 */
class NoteFraisVoter extends Voter
{
    public const CAN_SUBMIT   = 'NOTE_FRAIS_SUBMIT';
    public const CAN_VIEW     = 'NOTE_FRAIS_VIEW';
    public const CAN_VALIDATE = 'NOTE_FRAIS_VALIDATE';
    public const CAN_DELETE   = 'NOTE_FRAIS_DELETE';

    private const SUPPORTED = [
        self::CAN_SUBMIT,
        self::CAN_VIEW,
        self::CAN_VALIDATE,
        self::CAN_DELETE,
    ];

    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED, true)) {
            return false;
        }
        return $subject instanceof Club || $subject instanceof NoteFrais;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Super-admin bypass — toujours autorisé. Permet à admin@mabb.fr
        // d'intervenir si le trésorier d'un club est indisponible.
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        // Pour CAN_SUBMIT, le subject est un Club
        if ($attribute === self::CAN_SUBMIT) {
            if (!$subject instanceof Club) {
                return false;
            }
            // Super-admin OU membre actif du club (n'importe quel rôle)
            return $isSuperAdmin || $this->tenantResolver->userBelongsToClub($user, $subject);
        }

        // Pour les autres : le subject est une NoteFrais
        if (!$subject instanceof NoteFrais) {
            return false;
        }
        $club = $subject->getClub();
        if (!$club instanceof Club) {
            return false;
        }

        return match ($attribute) {
            self::CAN_VIEW     => $isSuperAdmin
                                  || $this->isOwner($user, $subject)
                                  || $this->isTresorier($user, $club),
            self::CAN_VALIDATE => ($isSuperAdmin || $this->isTresorier($user, $club))
                                  && $subject->isEnAttente(),
            self::CAN_DELETE   => $this->isOwner($user, $subject) && $subject->isEnAttente(),
            default            => false,
        };
    }

    private function isOwner(User $user, NoteFrais $note): bool
    {
        return $note->getDemandeur()?->getId() === $user->getId();
    }

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
