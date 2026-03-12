<?php

namespace App\Entity\Core;

use App\Repository\Core\UserClubRoleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Table pivot : User <-> Club <-> Rôle métier.
 *
 * UN utilisateur peut avoir PLUSIEURS rôles dans PLUSIEURS clubs.
 * Ex: Jean est COACH dans le club MABB ET PARENT dans le même club.
 *
 * Les rôles métier sont distincts des rôles Symfony (ROLE_USER, ROLE_SUPER_ADMIN).
 * Ils définissent ce que l'utilisateur PEUT FAIRE dans l'interface manager/pirb.
 */
#[ORM\Entity(repositoryClass: UserClubRoleRepository::class)]
#[ORM\Table(name: 'user_club_role')]
#[ORM\UniqueConstraint(name: 'unique_user_club_role', columns: ['user_id', 'club_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class UserClubRole
{
    // Rôles métier disponibles — enum PHP 8.1+ pour éviter les typos
    public const ROLE_DIRIGEANT  = 'DIRIGEANT';
    public const ROLE_COACH      = 'COACH';
    public const ROLE_STAFF      = 'STAFF';
    public const ROLE_JOUEUR     = 'JOUEUR';
    public const ROLE_PARENT     = 'PARENT';
    public const ROLE_BENEVOLE   = 'BENEVOLE';

    public const ROLES_DISPONIBLES = [
        self::ROLE_DIRIGEANT,
        self::ROLE_COACH,
        self::ROLE_STAFF,
        self::ROLE_JOUEUR,
        self::ROLE_PARENT,
        self::ROLE_BENEVOLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userClubRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Club::class, inversedBy: 'userClubRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Rôle métier dans ce club.
     * Valeurs : DIRIGEANT | COACH | STAFF | JOUEUR | PARENT | BENEVOLE
     */
    #[ORM\Column(length: 30)]
    private ?string $role = null;

    /** Le dirigeant peut activer/désactiver un rôle sans le supprimer */
    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // Méthodes utilitaires
    // -------------------------------------------------------------------------

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES_DISPONIBLES, true);
    }

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getClub(): ?Club
    {
        return $this->club;
    }

    public function setClub(?Club $club): static
    {
        $this->club = $club;
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!self::isValidRole($role)) {
            throw new \InvalidArgumentException(sprintf(
                'Rôle "%s" invalide. Rôles acceptés : %s',
                $role,
                implode(', ', self::ROLES_DISPONIBLES)
            ));
        }
        $this->role = $role;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
