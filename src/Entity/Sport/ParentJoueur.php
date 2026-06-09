<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\ParentJoueurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ParentJoueur — lien officiel Parent (User) ↔ Enfant (Joueur).
 *
 * Permet à un parent d'avoir accès au profil PIRB de son enfant
 * (lecture seule des stats, présences, cotisation enfant).
 *
 * Workflow PIRB V1.4c :
 *   - Parent demande lien depuis PIRB → status = pending
 *   - Staff/DIRIGEANT valide depuis Manager → status = active
 *   - Parent voit l'enfant dans /pirb/mes-enfants
 *
 * Implémente ClubAwareInterface via le Joueur (le club est celui de l'enfant).
 */
#[ORM\Entity(repositoryClass: ParentJoueurRepository::class)]
#[ORM\Table(name: 'parent_joueur')]
class ParentJoueur implements ClubAwareInterface
{
    public const STATUT_PENDING  = 'pending';
    public const STATUT_ACTIVE   = 'active';
    public const STATUT_REJECTED = 'rejected';

    public const DEMANDE_PAR_PARENT = 'parent';
    public const DEMANDE_PAR_STAFF  = 'staff';
    public const DEMANDE_PAR_JOUEUR = 'joueur';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'parent_user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $parentUser = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(name: 'joueur_id', nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PENDING;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $demandePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'valide_par_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }

    public function getId(): ?int { return $this->id; }

    public function getParentUser(): ?User { return $this->parentUser; }
    public function setParentUser(?User $u): static { $this->parentUser = $u; return $this; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): static { $this->joueur = $j; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): static
    {
        $this->statut = $s;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPending(): bool  { return $this->statut === self::STATUT_PENDING; }
    public function isActive(): bool   { return $this->statut === self::STATUT_ACTIVE; }
    public function isRejected(): bool { return $this->statut === self::STATUT_REJECTED; }

    public function getDemandePar(): ?string { return $this->demandePar; }
    public function setDemandePar(?string $d): static { $this->demandePar = $d; return $this; }

    public function getValidePar(): ?User { return $this->validePar; }
    public function setValidePar(?User $u): static { $this->validePar = $u; return $this; }

    public function getValideAt(): ?\DateTimeImmutable { return $this->valideAt; }
    public function setValideAt(?\DateTimeImmutable $d): static { $this->valideAt = $d; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
