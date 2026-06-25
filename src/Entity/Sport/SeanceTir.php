<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\SeanceTirRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * SeanceTir — session de saisie de tirs.
 *
 * Une séance regroupe tous les spots cliqués sur le terrain pour une même session.
 * Peut être liée à une Rencontre (source=MATCH) ou libre (source=ENTRAINEMENT).
 *
 * Workflow :
 *   ENTRAINEMENT : joueuse déclare via PIRB → validatedByCoach=false → coach valide
 *   MATCH        : généré automatiquement depuis les ActionMatch (session officielle)
 *                  → validatedByCoach=true d'office (coach a déjà validé la session live)
 */
#[ORM\Entity(repositoryClass: SeanceTirRepository::class)]
#[ORM\Table(name: 'seance_tir')]
#[ORM\HasLifecycleCallbacks]
class SeanceTir implements ClubAwareInterface
{
    public const SOURCE_ENTRAINEMENT = 'ENTRAINEMENT';
    public const SOURCE_MATCH        = 'MATCH';
    public const SOURCES = [self::SOURCE_ENTRAINEMENT, self::SOURCE_MATCH];

    public const TYPE_2PT_INT = '2pt_int';  // raquette / lay-up
    public const TYPE_2PT_EXT = '2pt_ext';  // mi-distance
    public const TYPE_3PT     = '3pt';
    public const TYPE_LANCER  = 'lancer';
    public const TYPES_TIR = [
        self::TYPE_2PT_INT,
        self::TYPE_2PT_EXT,
        self::TYPE_3PT,
        self::TYPE_LANCER,
    ];
    public const LABELS_TYPES = [
        self::TYPE_2PT_INT => 'Raquette / Lay-up (2pts)',
        self::TYPE_2PT_EXT => 'Mi-distance (2pts)',
        self::TYPE_3PT     => 'Trois points',
        self::TYPE_LANCER  => 'Lancer franc',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Rencontre liée — nullable.
     * Rempli si source=MATCH. Null si source=ENTRAINEMENT.
     */
    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Rencontre $rencontre = null;

    /**
     * Séance liée — nullable.
     * Optionnel même pour ENTRAINEMENT (la joueuse peut saisir sans lier à une séance).
     */
    #[ORM\ManyToOne(targetEntity: Seance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Seance $seance = null;

    /** ENTRAINEMENT | MATCH */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::SOURCES)]
    private string $source = self::SOURCE_ENTRAINEMENT;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateSeance = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Validé par un coach.
     * false par défaut pour les séances ENTRAINEMENT déclarées par la joueuse.
     * true d'office pour les séances MATCH (coach a déjà validé la session stats live).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $validatedByCoach = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'validated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $validatedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, ZoneTir> */
    #[ORM\OneToMany(targetEntity: ZoneTir::class, mappedBy: 'seanceTir', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $zones;

    public function __construct()
    {
        $this->zones = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====== Getters / Setters ======

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }
    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): static { $this->rencontre = $r; return $this; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): static { $this->seance = $s; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $s): static { $this->source = $s; return $this; }
    public function getDateSeance(): ?\DateTimeImmutable { return $this->dateSeance; }
    public function setDateSeance(\DateTimeImmutable $d): static { $this->dateSeance = $d; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): static { $this->notes = $n; return $this; }
    public function isValidatedByCoach(): bool { return $this->validatedByCoach; }
    public function getValidatedBy(): ?User { return $this->validatedBy; }
    public function getValidatedAt(): ?\DateTimeImmutable { return $this->validatedAt; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function isMatch(): bool { return $this->source === self::SOURCE_MATCH; }
    public function isEntrainement(): bool { return $this->source === self::SOURCE_ENTRAINEMENT; }

    /**
     * Validation par un coach.
     */
    public function valider(User $coach): static
    {
        $this->validatedByCoach = true;
        $this->validatedBy      = $coach;
        $this->validatedAt      = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, ZoneTir> */
    public function getZones(): Collection { return $this->zones; }

    public function addZone(ZoneTir $zone): static
    {
        if (!$this->zones->contains($zone)) {
            $this->zones->add($zone);
            $zone->setSeanceTir($this);
        }
        return $this;
    }

    public function removeZone(ZoneTir $zone): static
    {
        $this->zones->removeElement($zone);
        return $this;
    }

    // ====== Stats agrégées ======

    /**
     * Total des tentatives sur toute la séance.
     */
    public function getTotalTentatives(): int
    {
        return array_sum($this->zones->map(fn(ZoneTir $z) => $z->getTentatives())->toArray());
    }

    /**
     * Total des tirs réussis sur toute la séance.
     */
    public function getTotalReussis(): int
    {
        return array_sum($this->zones->map(fn(ZoneTir $z) => $z->getReussis())->toArray());
    }

    /**
     * Pourcentage global de la séance.
     */
    public function getPourcentageGlobal(): ?float
    {
        $t = $this->getTotalTentatives();
        if ($t === 0) return null;
        return round($this->getTotalReussis() / $t * 100, 1);
    }

    /**
     * Stats par type de tir — utile pour le détail par zone.
     * Retourne ['3pt' => ['tentatives' => 16, 'reussis' => 9, 'pct' => 56.3], ...]
     *
     * @return array<string, array{tentatives: int, reussis: int, pct: float|null}>
     */
    public function getStatsByType(): array
    {
        $stats = [];
        foreach (self::TYPES_TIR as $type) {
            $stats[$type] = ['tentatives' => 0, 'reussis' => 0, 'pct' => null];
        }
        foreach ($this->zones as $zone) {
            $t = $zone->getTypeTir();
            $stats[$t]['tentatives'] += $zone->getTentatives();
            $stats[$t]['reussis']    += $zone->getReussis();
        }
        foreach ($stats as $type => &$data) {
            $data['pct'] = $data['tentatives'] > 0
                ? round($data['reussis'] / $data['tentatives'] * 100, 1)
                : null;
        }
        return $stats;
    }
}
