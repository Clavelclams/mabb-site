<?php

namespace App\Entity\Core;

use App\Repository\Core\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Un compte existe déjà avec cet email.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'L\'email est obligatoire.')]
    #[Assert\Email(message: 'L\'email {{ value }} n\'est pas valide.')]
    private ?string $email = null;

    /**
     * Roles Symfony (ROLE_USER par défaut, ROLE_SUPER_ADMIN possible).
     * Les rôles MÉTIER (COACH, JOUEUR...) sont dans UserClubRole, pas ici.
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    private ?string $prenom = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    private ?string $nom = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    /** Compte actif ou désactivé par un admin */
    #[ORM\Column]
    private bool $isActive = true;

    /** Consentement RGPD explicite requis à l'inscription */
    #[ORM\Column]
    private bool $rgpdConsent = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rgpdConsentAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPublic = false;

    /** Rôles vitrine (multi-valeurs). 'benevole' toujours présent, non supprimable. */
    #[ORM\Column(type: 'json')]
    private array $rolesMembre = ['benevole'];

    /** Relation vers les clubs/rôles métier de cet utilisateur */
    #[ORM\OneToMany(targetEntity: UserClubRole::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private Collection $userClubRoles;

    public function __construct()
    {
        $this->userClubRoles = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // UserInterface
    // -------------------------------------------------------------------------

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Retourne les rôles Symfony (toujours ROLE_USER minimum).
     * NE PAS mettre les rôles métier ici.
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // Si tu stockes un mot de passe en clair temporairement, efface-le ici
    }

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;
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

    public function isRgpdConsent(): bool
    {
        return $this->rgpdConsent;
    }

    public function setRgpdConsent(bool $rgpdConsent): static
    {
        $this->rgpdConsent = $rgpdConsent;
        if ($rgpdConsent && $this->rgpdConsentAt === null) {
            $this->rgpdConsentAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getRgpdConsentAt(): ?\DateTimeImmutable
    {
        return $this->rgpdConsentAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    /** @return Collection<int, UserClubRole> */
    public function getUserClubRoles(): Collection
    {
        return $this->userClubRoles;
    }

    public function addUserClubRole(UserClubRole $userClubRole): static
    {
        if (!$this->userClubRoles->contains($userClubRole)) {
            $this->userClubRoles->add($userClubRole);
            $userClubRole->setUser($this);
        }
        return $this;
    }

    public function removeUserClubRole(UserClubRole $userClubRole): static
    {
        $this->userClubRoles->removeElement($userClubRole);
        return $this;
    }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): static { $this->bio = $bio; return $this; }

    public function getPhotoPath(): ?string { return $this->photoPath; }
    public function setPhotoPath(?string $photoPath): static { $this->photoPath = $photoPath; return $this; }

    public function isPublic(): bool { return $this->isPublic; }
    public function setIsPublic(bool $isPublic): static { $this->isPublic = $isPublic; return $this; }

    public function getRolesMembre(): array { return $this->rolesMembre; }

    /**
     * Définit les rôles vitrine — force toujours 'benevole' dans le tableau.
     */
    public function setRolesMembre(array $roles): static
    {
        if (!in_array('benevole', $roles)) {
            array_unshift($roles, 'benevole');
        }
        $this->rolesMembre = array_values(array_unique($roles));
        return $this;
    }

    /** Vérifie si l'utilisateur possède un rôle vitrine donné. */
    public function hasRoleMembre(string $role): bool
    {
        return in_array($role, $this->rolesMembre);
    }

    /** Ajoute un rôle vitrine sans écraser les autres. */
    public function addRoleMembre(string $role): static
    {
        if (!in_array($role, $this->rolesMembre)) {
            $this->rolesMembre[] = $role;
        }
        return $this;
    }

    /** Retire un rôle vitrine — 'benevole' est protégé et ne peut pas être retiré. */
    public function removeRoleMembre(string $role): static
    {
        if ($role === 'benevole') {
            return $this;
        }
        $this->rolesMembre = array_values(array_filter($this->rolesMembre, fn($r) => $r !== $role));
        return $this;
    }
}
