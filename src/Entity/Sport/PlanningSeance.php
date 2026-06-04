<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\PlanningSeanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PlanningSeance — créneau récurrent d'entraînement d'une équipe.
 *
 * Exemple : U13F a entraînement tous les MARDI 18h00-19h30 au Gymnase Étouvie.
 * Une équipe peut avoir PLUSIEURS créneaux récurrents (mardi + jeudi par exemple).
 *
 * À partir de ce pattern, le service GenerateurSeancesService crée toutes les
 * Seance réelles pour la saison (≈80 séances par créneau).
 *
 * Workflow détachement : une Seance issue d'un planning pointe vers son
 * PlanningSeance source via Seance::planningSource. Si l'admin modifie la
 * séance individuellement, elle se détache du pattern (planningSource = null)
 * et n'est plus impactée par les régénérations futures.
 */
#[ORM\Entity(repositoryClass: PlanningSeanceRepository::class)]
#[ORM\Table(name: 'planning_seance')]
#[ORM\HasLifecycleCallbacks]
class PlanningSeance implements ClubAwareInterface
{
    /** Mapping jour de semaine → nom français (ISO-8601 : lundi=1, dimanche=7) */
    public const JOURS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
        7 => 'Dimanche',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Equipe $equipe = null;

    /** 1 = Lundi, 7 = Dimanche (ISO-8601) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 7)]
    private ?int $jourSemaine = null;

    /** Heure de début format "HH:MM" (ex: "18:00") */
    #[ORM\Column(length: 5)]
    #[Assert\Regex('/^([01]\d|2[0-3]):[0-5]\d$/', message: 'Format heure attendu : HH:MM (ex: 18:00)')]
    private ?string $heureDebut = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 15, max: 240)]
    private int $dureeMinutes = 90;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private ?string $lieu = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: Seance::TYPES)]
    private string $type = 'Entrainement';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Helper utilisé dans les templates pour afficher "Mardi 18:00 (1h30)" */
    public function getResume(): string
    {
        $jour = self::JOURS[$this->jourSemaine] ?? '?';
        $duree = $this->dureeMinutes < 60
            ? $this->dureeMinutes . 'min'
            : sprintf('%dh%02d', intdiv($this->dureeMinutes, 60), $this->dureeMinutes % 60);
        return sprintf('%s %s (%s)', $jour, $this->heureDebut, $duree);
    }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }
    public function getJourSemaine(): ?int { return $this->jourSemaine; }
    public function setJourSemaine(int $j): static { $this->jourSemaine = $j; return $this; }
    public function getHeureDebut(): ?string { return $this->heureDebut; }
    public function setHeureDebut(string $h): static { $this->heureDebut = $h; return $this; }
    public function getDureeMinutes(): int { return $this->dureeMinutes; }
    public function setDureeMinutes(int $d): static { $this->dureeMinutes = $d; return $this; }
    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(string $lieu): static { $this->lieu = $lieu; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
