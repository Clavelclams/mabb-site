<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\RencontreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rencontre (match) vs un adversaire.
 *
 * Renommé "Rencontre" pour éviter le mot-clé 'match' de PHP 8+.
 *
 * Workflow statut : brouillon -> validé -> verrouillé.
 * Une fois verrouillée, la feuille de match ne peut plus être modifiée
 * sans privilège super_admin (anti-triche après diffusion des résultats).
 */
#[ORM\Entity(repositoryClass: RencontreRepository::class)]
#[ORM\Table(name: 'rencontre')]
#[ORM\HasLifecycleCallbacks]
class Rencontre implements ClubAwareInterface
{
    public const STATUT_BROUILLON  = 'brouillon';
    public const STATUT_VALIDE     = 'valide';
    public const STATUT_VERROUILLE = 'verrouille';
    public const STATUTS = [self::STATUT_BROUILLON, self::STATUT_VALIDE, self::STATUT_VERROUILLE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'rencontres')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'équipe est obligatoire.')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $adversaire = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column]
    private bool $domicile = true;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $scoreEquipe = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $scoreAdverse = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS)]
    private ?string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Convocation> */
    #[ORM\OneToMany(targetEntity: Convocation::class, mappedBy: 'rencontre', cascade: ['remove'])]
    private Collection $convocations;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'rencontre', cascade: ['remove'])]
    private Collection $presences;

    public function __construct()
    {
        $this->convocations = new ArrayCollection();
        $this->presences = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isVerrouillee(): bool { return $this->statut === self::STATUT_VERROUILLE; }
    public function aResultat(): bool { return $this->scoreEquipe !== null && $this->scoreAdverse !== null; }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }
    public function getAdversaire(): ?string { return $this->adversaire; }
    public function setAdversaire(string $adv): static { $this->adversaire = $adv; return $this; }
    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(?string $lieu): static { $this->lieu = $lieu; return $this; }
    public function isDomicile(): bool { return $this->domicile; }
    public function setDomicile(bool $dom): static { $this->domicile = $dom; return $this; }
    public function getScoreEquipe(): ?int { return $this->scoreEquipe; }
    public function setScoreEquipe(?int $s): static { $this->scoreEquipe = $s; return $this; }
    public function getScoreAdverse(): ?int { return $this->scoreAdverse; }
    public function setScoreAdverse(?int $s): static { $this->scoreAdverse = $s; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getConvocations(): Collection { return $this->convocations; }
    public function getPresences(): Collection { return $this->presences; }
}
