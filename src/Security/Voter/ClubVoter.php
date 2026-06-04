<?php

namespace App\Security\Voter;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Security\Tenant\TenantResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ClubVoter : vérifie les droits d'un user sur un club OU sur une entité du club.
 *
 * Accepte deux types de subject :
 *   1. Un objet Club directement
 *      $this->denyAccessUnlessGranted('CLUB_STAFF', $club);
 *   2. Toute entité implémentant ClubAwareInterface (Equipe, Joueur, Seance,
 *      Rencontre...). Le Voter extrait automatiquement le Club via getClub().
 *      $this->denyAccessUnlessGranted('CLUB_STAFF', $equipe);
 *
 * Ce design suit l'Open/Closed Principle : pour protéger une nouvelle entité,
 * il suffit de lui faire implémenter ClubAwareInterface — pas de modification
 * du Voter requise.
 *
 * @extends Voter<string, Club|ClubAwareInterface>
 */
class ClubVoter extends Voter
{
    // Attributs disponibles
    public const CLUB_MEMBER   = 'CLUB_MEMBER';   // Appartenir au club (tout rôle)
    public const CLUB_COACH    = 'CLUB_COACH';    // Être COACH dans ce club
    public const CLUB_ADMIN    = 'CLUB_ADMIN';    // Être DIRIGEANT dans ce club
    public const CLUB_STAFF    = 'CLUB_STAFF';    // Être STAFF ou COACH ou DIRIGEANT
    public const CLUB_JOUEUR   = 'CLUB_JOUEUR';   // Être JOUEUR dans ce club

    private const SUPPORTED_ATTRIBUTES = [
        self::CLUB_MEMBER,
        self::CLUB_COACH,
        self::CLUB_ADMIN,
        self::CLUB_STAFF,
        self::CLUB_JOUEUR,
    ];

    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)) {
            return false;
        }
        // Accepte un Club directement OU toute entité qui appartient à un club
        return $subject instanceof Club
            || $subject instanceof ClubAwareInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Extrait le Club du subject :
        //   - si Club directement → on l'utilise tel quel
        //   - si ClubAwareInterface → on appelle getClub() pour récupérer le Club
        $club = $subject instanceof Club
            ? $subject
            : $subject->getClub();

        // Cas pathologique : entité ClubAware sans club rattaché (ne devrait
        // jamais arriver en BDD car contrainte NOT NULL, mais protège contre
        // les bugs côté code (ex: $entity = new Equipe() sans setClub())
        if (!$club instanceof Club) {
            return false;
        }

        return match ($attribute) {
            self::CLUB_MEMBER => $this->isMember($user, $club),
            self::CLUB_COACH  => $this->hasMetaRole($user, $club, UserClubRole::ROLE_COACH),
            self::CLUB_ADMIN  => $this->hasMetaRole($user, $club, UserClubRole::ROLE_DIRIGEANT),
            self::CLUB_STAFF  => $this->isStaffOrAbove($user, $club),
            self::CLUB_JOUEUR => $this->hasMetaRole($user, $club, UserClubRole::ROLE_JOUEUR),
            default           => false,
        };
    }

    private function isMember(User $user, Club $club): bool
    {
        return $this->tenantResolver->userBelongsToClub($user, $club);
    }

    private function hasMetaRole(User $user, Club $club, string $metaRole): bool
    {
        foreach ($user->getUserClubRoles() as $ucr) {
            if (
                $ucr->getClub()?->getId() === $club->getId()
                && $ucr->getRole() === $metaRole
                && $ucr->isActive()
            ) {
                return true;
            }
        }
        return false;
    }

    private function isStaffOrAbove(User $user, Club $club): bool
    {
        $staffRoles = [
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
        ];
        foreach ($staffRoles as $role) {
            if ($this->hasMetaRole($user, $club, $role)) {
                return true;
            }
        }
        return false;
    }
}
