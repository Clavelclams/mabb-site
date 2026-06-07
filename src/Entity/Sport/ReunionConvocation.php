<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\ReunionConvocationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ReunionConvocation — un membre convoqué à une réunion + son statut de présence.
 *
 * UNE LIGNE = 1 (Reunion × User). Contrainte unique BDD.
 *
 * STATUTS :
 *   - CONVOQUE : on attend la réunion (par défaut à la création)
 *   - PRESENT  : a participé (à saisir après réunion par secrétaire)
 *   - EXCUSE   : absent excusé (a prévenu)
 *   - ABSENT   : absent non-excusé
 *
 * MULTI-TENANT : délégué via $this->reunion->getClub().
 *
 * Sert aussi à afficher sur le dashboard de l'utilisateur : ses prochaines
 * réunions et les PV à lire (filtrés par convocations qui le concernent).
 */
#[ORM\Entity(repositoryClass: ReunionConvocationRepository::class)]
#[ORM\Table(name: 'reunion_convocation')]
#[ORM\UniqueConstraint(name: 'unique_reunion_user', columns: ['reunion_id', 'user_id'])]
#[ORM\Index(name: 'idx_rc_user_pv_lu', columns: ['user_id', 'pv_lu_at'])]
#[ORM\HasLifecycleCallbacks]
class ReunionConvocation implements ClubAwareInterface
{
    public const STATUT_CONVOQUE = 'convoque';
    public const STATUT_PRESENT  = 'present';
    public const STATUT_EXCUSE   = 'excuse';
    public const STATUT_ABSENT   = 'absent';

    public const STATUTS = [
        self::STATUT_CONVOQUE,
        self::STATUT_PRESENT,
        self::STATUT_EXCUSE,
        self::STATUT_ABSENT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reunion::class, inversedBy: 'convocations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reunion $reunion = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS)]
    private string $statut = self::STATUT_CONVOQUE;

    /**
     * Note libre du membre (raison d'absence, point à ajouter à l'ODJ, etc.)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /**
     * Timestamp de lecture du PV par ce membre.
     * Permet de marquer "Nouveau PV à lire" sur le dashboard.
     * Null = pas encore lu.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pvLuAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // MULTI-TENANT — via la réunion
    // ====================================================================

    public function getClub(): ?Club
    {
        return $this->reunion?->getClub();
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getReunion(): ?Reunion { return $this->reunion; }
    public function setReunion(?Reunion $r): static { $this->reunion = $r; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }

    public function getPvLuAt(): ?\DateTimeImmutable { return $this->pvLuAt; }
    public function setPvLuAt(?\DateTimeImmutable $dt): static { $this->pvLuAt = $dt; return $this; }
    public function isPvLu(): bool { return $this->pvLuAt !== null; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * Helper : marque le PV comme lu maintenant.
     * Idempotent : ne fait rien si déjà lu (évite de réécrire la date).
     */
    public function marquerPvLu(): static
    {
        if ($this->pvLuAt === null) {
            $this->pvLuAt = new \DateTimeImmutable();
        }
        return $this;
    }
}
