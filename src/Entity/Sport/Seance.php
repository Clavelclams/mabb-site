<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\SeanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Séance d'entraînement (ou stage, ou prépa-match).
 *
 * Distincte d'une Rencontre : la séance c'est "travail interne",
 * la rencontre c'est "compétition vs adversaire".
 */
#[ORM\Entity(repositoryClass: SeanceRepository::class)]
#[ORM\Table(name: 'seance')]
#[ORM\HasLifecycleCallbacks]
class Seance implements ClubAwareInterface
{
    public const TYPES = ['Entrainement','Stage','Prepa-match'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'équipe est obligatoire.')]
    private ?Equipe $equipe = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull(message: 'La date et l\'heure sont obligatoires.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Le lieu est obligatoire (ex: Gymnase Étouvie).')]
    #[Assert\Length(max: 120)]
    private ?string $lieu = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dureeMinutes = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES)]
    private ?string $type = 'Entrainement';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * PlanningSeance source — si la séance a été générée à partir d'un planning
     * récurrent, on garde le lien. Permet de :
     *   - Régénérer/synchroniser les futures séances quand le planning change
     *   - Distinguer les séances "patron" des séances exceptionnelles (planningSource = null)
     *
     * Détachement : dès qu'on modifie la séance individuellement (lieu, heure
     * différente du pattern), on remet planningSource à null pour que les
     * régénérations futures ne l'écrasent pas.
     */
    #[ORM\ManyToOne(targetEntity: PlanningSeance::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PlanningSeance $planningSource = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'seance', cascade: ['remove'])]
    private Collection $presences;

    public function __construct()
    {
        $this->presences = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }
    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(string $lieu): static { $this->lieu = $lieu; return $this; }
    public function getDureeMinutes(): ?int { return $this->dureeMinutes; }
    public function setDureeMinutes(?int $d): static { $this->dureeMinutes = $d; return $this; }
    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getPresences(): Collection { return $this->presences; }
    public function getPlanningSource(): ?PlanningSeance { return $this->planningSource; }
    public function setPlanningSource(?PlanningSeance $p): static { $this->planningSource = $p; return $this; }

    /**
     * Helper jury : retourne true si la séance est issue d'un planning récurrent
     * (et donc régénérable). False si exceptionnelle ou détachée.
     */
    public function estIssueDunPlanning(): bool
    {
        return $this->planningSource !== null;
    }
}
