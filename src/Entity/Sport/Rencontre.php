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

    /**
     * Indique si la FFBB a désigné un arbitre officiel pour ce match.
     * Si oui, aucune inscription bénévole interne n'est possible (le bouton
     * est désactivé dans l'UI). Si non, un membre du club peut s'inscrire
     * comme arbitre bénévole — pratique pour les catégories jeunes (U13, U15)
     * où l'arbitrage est souvent assuré par les clubs.
     */
    #[ORM\Column]
    private bool $arbitreExterneDesigne = false;

    /**
     * Nom de l'arbitre officiel FFBB (si désigné). Champ informatif libre,
     * récupéré depuis la convocation FFBB. Affiché sur la fiche rencontre.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $arbitreExterneNom = null;

    /**
     * Rôles bénévoles internes (arbitres, marqueur, chrono, e-marque, stats…).
     * Voir entité RencontreRole pour le détail. Chaque rôle peut être pris
     * par un User différent ; la table fait office de planning officiel.
     *
     * @var Collection<int, RencontreRole>
     */
    #[ORM\OneToMany(targetEntity: RencontreRole::class, mappedBy: 'rencontre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $roles;

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
        $this->roles = new ArrayCollection();
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

    // ====== Arbitrage FFBB ======
    public function isArbitreExterneDesigne(): bool { return $this->arbitreExterneDesigne; }
    public function setArbitreExterneDesigne(bool $v): static { $this->arbitreExterneDesigne = $v; return $this; }

    public function getArbitreExterneNom(): ?string { return $this->arbitreExterneNom; }
    public function setArbitreExterneNom(?string $nom): static { $this->arbitreExterneNom = $nom; return $this; }

    /** @return Collection<int, RencontreRole> */
    public function getRoles(): Collection { return $this->roles; }

    /**
     * Récupère le RencontreRole pour un rôle donné (ARBITRE_1, MARQUEUR, etc.),
     * ou null si personne n'est inscrit sur ce rôle.
     */
    public function getRoleParCode(string $codeRole): ?RencontreRole
    {
        foreach ($this->roles as $r) {
            if ($r->getRole() === $codeRole) return $r;
        }
        return null;
    }

    /**
     * True si les rôles d'arbitrage interne peuvent être pris par des bénévoles.
     * Faux si la FFBB a désigné un arbitre officiel (case "arbitre externe" cochée).
     */
    public function peutRecevoirArbitreBenevole(): bool
    {
        return !$this->arbitreExterneDesigne;
    }
}
