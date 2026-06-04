<?php

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\EvenementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Evenement — toute activité club hors-match : AG, réunion, tournoi interne,
 * sortie, formation, fête, etc.
 *
 * Distinct de Rencontre (qui est un match officiel FFBB avec adversaire,
 * score, convocations). Ici, les participants s'inscrivent eux-mêmes via
 * EvenementParticipation — initiative du membre, pas du coach.
 *
 * Workflow staff :
 *   1. Création en statut BROUILLON (pas visible des membres)
 *   2. Quand prêt → bascule en PUBLIE → visible et inscriptions ouvertes
 *   3. Si besoin → bascule en ANNULE (gardé en historique, plus d'inscriptions)
 *
 * Lien gamification : quand le staff marque un participant "présent" après
 * l'événement, une Mission est auto-créée pour ce Joueur (s'il existe), qui
 * alimente l'XP et les badges Axe C bénévolat.
 */
#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[ORM\Table(name: 'sport_evenement')]
#[ORM\Index(name: 'idx_evenement_club_date', columns: ['club_id', 'date'])]
#[ORM\Index(name: 'idx_evenement_statut', columns: ['statut'])]
#[ORM\HasLifecycleCallbacks]
class Evenement implements ClubAwareInterface
{
    // ===== TYPES =====
    public const TYPE_REUNION         = 'reunion';
    public const TYPE_AG              = 'ag';
    public const TYPE_TOURNOI_INTERNE = 'tournoi_interne';
    public const TYPE_SORTIE          = 'sortie';
    public const TYPE_FORMATION       = 'formation';
    public const TYPE_FETE            = 'fete';
    public const TYPE_AUTRE           = 'autre';

    public const TYPES = [
        self::TYPE_REUNION,
        self::TYPE_AG,
        self::TYPE_TOURNOI_INTERNE,
        self::TYPE_SORTIE,
        self::TYPE_FORMATION,
        self::TYPE_FETE,
        self::TYPE_AUTRE,
    ];

    public const TYPE_LIBELLES = [
        self::TYPE_REUNION         => 'Réunion',
        self::TYPE_AG              => 'Assemblée Générale',
        self::TYPE_TOURNOI_INTERNE => 'Tournoi interne',
        self::TYPE_SORTIE          => 'Sortie',
        self::TYPE_FORMATION       => 'Formation',
        self::TYPE_FETE            => 'Fête / convivialité',
        self::TYPE_AUTRE           => 'Autre',
    ];

    // ===== STATUTS =====
    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_PUBLIE    = 'publie';
    public const STATUT_ANNULE    = 'annule';

    public const STATUTS = [self::STATUT_BROUILLON, self::STATUT_PUBLIE, self::STATUT_ANNULE];

    // ===== OUVERT_A =====
    public const OUVERT_TOUS      = 'tous';
    public const OUVERT_JOUEURS   = 'joueurs';
    public const OUVERT_BENEVOLES = 'benevoles';
    public const OUVERT_STAFF     = 'staff';

    public const OUVERT_VALEURS = [
        self::OUVERT_TOUS,
        self::OUVERT_JOUEURS,
        self::OUVERT_BENEVOLES,
        self::OUVERT_STAFF,
    ];

    public const OUVERT_LIBELLES = [
        self::OUVERT_TOUS      => 'Tous les membres',
        self::OUVERT_JOUEURS   => 'Joueurs uniquement',
        self::OUVERT_BENEVOLES => 'Bénévoles uniquement',
        self::OUVERT_STAFF     => 'Staff uniquement',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES, message: 'Type d\'événement invalide.')]
    private ?string $type = self::TYPE_AUTRE;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS, message: 'Statut invalide.')]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::OUVERT_VALEURS)]
    private string $ouvertA = self::OUVERT_TOUS;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 999, notInRangeMessage: 'Entre 1 et 999.')]
    private ?int $inscriptionsMax = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, EvenementParticipation> */
    #[ORM\OneToMany(targetEntity: EvenementParticipation::class, mappedBy: 'evenement', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $participations;

    public function __construct()
    {
        $this->participations = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ============ Getters / Setters ============

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(?string $titre): static { $this->titre = $titre; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }
    public function getTypeLibelle(): string { return self::TYPE_LIBELLES[$this->type] ?? ($this->type ?? ''); }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(?\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getDateFin(): ?\DateTimeImmutable { return $this->dateFin; }
    public function setDateFin(?\DateTimeImmutable $dateFin): static { $this->dateFin = $dateFin; return $this; }

    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(?string $lieu): static { $this->lieu = $lieu; return $this; }

    public function getOuvertA(): string { return $this->ouvertA; }
    public function setOuvertA(string $ouvertA): static { $this->ouvertA = $ouvertA; return $this; }
    public function getOuvertALibelle(): string { return self::OUVERT_LIBELLES[$this->ouvertA] ?? $this->ouvertA; }

    public function getInscriptionsMax(): ?int { return $this->inscriptionsMax; }
    public function setInscriptionsMax(?int $inscriptionsMax): static { $this->inscriptionsMax = $inscriptionsMax; return $this; }

    public function getCreateur(): ?User { return $this->createur; }
    public function setCreateur(?User $createur): static { $this->createur = $createur; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, EvenementParticipation> */
    public function getParticipations(): Collection { return $this->participations; }

    // ============ Helpers métier ============

    public function isBrouillon(): bool { return $this->statut === self::STATUT_BROUILLON; }
    public function isPublie(): bool    { return $this->statut === self::STATUT_PUBLIE; }
    public function isAnnule(): bool    { return $this->statut === self::STATUT_ANNULE; }
    public function isPasse(): bool     { return $this->date !== null && $this->date < new \DateTimeImmutable(); }
    public function isComplet(): bool
    {
        return $this->inscriptionsMax !== null
            && $this->participations->count() >= $this->inscriptionsMax;
    }
}
