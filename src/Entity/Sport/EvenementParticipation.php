<?php

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\EvenementParticipationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * EvenementParticipation — lien entre un User et un Evenement auquel
 * il s'est inscrit (ou auquel le staff l'a inscrit après coup).
 *
 * Statuts :
 *   - inscrit  : le user a cliqué "Je participe" (avant l'événement)
 *   - present  : marqué présent par le staff après l'événement
 *   - absent   : ne s'est pas présenté
 *   - excuse   : absent mais a prévenu
 *
 * Le passage en "present" déclenche la création automatique d'une Mission
 * (axe C bénévolat de la gamification) — cf. EvenementController::marquerPresent.
 */
#[ORM\Entity(repositoryClass: EvenementParticipationRepository::class)]
#[ORM\Table(name: 'sport_evenement_participation')]
#[ORM\UniqueConstraint(name: 'uniq_evenement_user', columns: ['evenement_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class EvenementParticipation implements ClubAwareInterface
{
    public const STATUT_INSCRIT = 'inscrit';
    public const STATUT_PRESENT = 'present';
    public const STATUT_ABSENT  = 'absent';
    public const STATUT_EXCUSE  = 'excuse';

    public const STATUTS = [
        self::STATUT_INSCRIT,
        self::STATUT_PRESENT,
        self::STATUT_ABSENT,
        self::STATUT_EXCUSE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evenement::class, inversedBy: 'participations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Evenement $evenement = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_INSCRIT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEvenement(): ?Evenement { return $this->evenement; }
    public function setEvenement(?Evenement $evenement): static { $this->evenement = $evenement; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): static { $this->commentaire = $commentaire; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getClub(): ?Club { return $this->evenement?->getClub(); }
}
