<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\SeanceSoloRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SeanceSolo — entraînement individuel déclaré par la joueuse.
 *
 * Workflow :
 *   1. Joueuse déclare "j'ai fait 30min de shoot" (PIRB → POST /seances/solo/declarer)
 *   2. Statut = pending → apparaît dans le tableau du coach (Manager)
 *   3. Coach valide / refuse (en masse ou un par un)
 *   4. Si validé → compte dans le bilan de présences actives de la joueuse
 *
 * Différence avec une Seance officielle :
 *   - Pas d'équipe ni de planning source
 *   - Pas de feuille de présence signée
 *   - Visible uniquement sur la fiche de la joueuse concernée
 */
#[ORM\Entity(repositoryClass: SeanceSoloRepository::class)]
#[ORM\Table(name: 'seance_solo')]
#[ORM\HasLifecycleCallbacks]
class SeanceSolo
{
    public const TYPES = [
        'Shoot'    => 'Travail du tir',
        'Dribble'  => 'Dribble / Maniement',
        'Physique' => 'Prépa physique',
        'Tactique' => 'Analyse / Tactique',
        'Autre'    => 'Autre',
    ];

    public const STATUT_PENDING  = 'pending';
    public const STATUT_APPROVED = 'approved';
    public const STATUT_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    #[Assert\LessThanOrEqual('today', message: 'La date ne peut pas être dans le futur.')]
    private ?\DateTimeImmutable $dateSolo = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 15, max: 300, notInRangeMessage: 'Durée entre 15 et 300 minutes.')]
    private int $dureeMinutes = 60;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(callback: [self::class, 'getTypeKeys'])]
    private string $type = 'Shoot';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500, maxMessage: '500 caractères max.')]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $messageCoach = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    // ─── Getters / Setters ────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getDateSolo(): ?\DateTimeImmutable { return $this->dateSolo; }
    public function setDateSolo(?\DateTimeImmutable $d): self { $this->dateSolo = $d; return $this; }
    public function getDureeMinutes(): int { return $this->dureeMinutes; }
    public function setDureeMinutes(int $d): self { $this->dureeMinutes = $d; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $t): self { $this->type = $t; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }
    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): self { $this->statut = $s; return $this; }
    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function getMessageCoach(): ?string { return $this->messageCoach; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    // ─── Helpers ─────────────────────────────────────────────────────────

    public static function getTypeKeys(): array { return array_keys(self::TYPES); }

    public function getLabelType(): string { return self::TYPES[$this->type] ?? $this->type; }

    public function isPending(): bool  { return $this->statut === self::STATUT_PENDING; }
    public function isApproved(): bool { return $this->statut === self::STATUT_APPROVED; }
    public function isRejected(): bool { return $this->statut === self::STATUT_REJECTED; }

    public function approuver(User $coach, ?string $message = null): void
    {
        $this->statut      = self::STATUT_APPROVED;
        $this->validatedBy = $coach;
        $this->validatedAt = new \DateTimeImmutable();
        $this->messageCoach = $message;
    }

    public function refuser(User $coach, ?string $message = null): void
    {
        $this->statut      = self::STATUT_REJECTED;
        $this->validatedBy = $coach;
        $this->validatedAt = new \DateTimeImmutable();
        $this->messageCoach = $message;
    }
}
