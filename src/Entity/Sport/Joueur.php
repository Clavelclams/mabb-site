<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\JoueurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Joueuse du club. ENTITÉ DÉCOUPLÉE DE USER : un joueur peut ne PAS avoir
 * de compte utilisateur (beaucoup de jeunes licenciées ne s'inscrivent jamais
 * sur l'app), et un User peut être rattaché à plusieurs joueuses (parents).
 *
 * Cette séparation est inspirée du pattern Participant de VEA — éviter le
 * piège "pas de compte = pas de joueuse".
 */
#[ORM\Entity(repositoryClass: JoueurRepository::class)]
#[ORM\Table(name: 'joueur')]
#[ORM\HasLifecycleCallbacks]
class Joueur implements ClubAwareInterface
{
    public const POSTES = ['Meneuse','Arrière','Ailière','Intérieure','Polyvalente'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Affectation équipe — peut être null (joueuse en attente d'équipe) */
    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'joueurs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $prenom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Choice(choices: self::POSTES, message: 'Poste invalide.', match: true)]
    private ?string $poste = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $numeroMaillot = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $licence = null;

    /**
     * Email du joueur — clé secondaire de match User ↔ Joueur au signup
     * (utilisée si pas de licence ou si le user s'inscrit sans renseigner sa licence).
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Email invalide.')]
    private ?string $email = null;

    /**
     * Téléphone du joueur — clé tertiaire de match User ↔ Joueur au signup
     * (utilisée pour les bénévoles/joueurs jeunes sans email).
     * Format libre, normalisation côté code (strip espaces/points/tirets) avant comparaison.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /**
     * Notes libres : infos medicales, contact urgence, observations coach.
     * Confidentiel — visible uniquement par le staff (CLUB_STAFF+).
     * Important : ces donnees peuvent contenir des infos sensibles (sante)
     * — ne PAS les exposer dans des vues publiques ou API.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** Lien optionnel vers un compte User */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'joueur', cascade: ['remove'])]
    private Collection $presences;

    /** @var Collection<int, Convocation> */
    #[ORM\OneToMany(targetEntity: Convocation::class, mappedBy: 'joueur', cascade: ['remove'])]
    private Collection $convocations;

    public function __construct()
    {
        $this->presences = new ArrayCollection();
        $this->convocations = new ArrayCollection();
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
    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }
    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }
    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $d): static { $this->dateNaissance = $d; return $this; }
    public function getPoste(): ?string { return $this->poste; }
    public function setPoste(?string $poste): static { $this->poste = $poste; return $this; }
    public function getNumeroMaillot(): ?int { return $this->numeroMaillot; }
    public function setNumeroMaillot(?int $n): static { $this->numeroMaillot = $n; return $this; }
    public function getLicence(): ?string { return $this->licence; }
    public function setLicence(?string $licence): static { $this->licence = $licence; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static
    {
        $this->email = $email ? strtolower(trim($email)) : null;
        return $this;
    }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $tel): static { $this->telephone = $tel; return $this; }

    /**
     * Téléphone normalisé pour comparaison : sans espaces, points, tirets, parenthèses.
     * "+33 6 12 34 56 78" et "0612345678" donnent la même chose (ou presque).
     */
    public function getTelephoneNormalise(): ?string
    {
        if ($this->telephone === null) return null;
        $clean = preg_replace('/[\s\.\-\(\)]/', '', $this->telephone);
        // Convertit +33X en 0X si format français
        if (str_starts_with($clean, '+33')) {
            $clean = '0' . substr($clean, 3);
        }
        return $clean;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getPresences(): Collection { return $this->presences; }
    public function getConvocations(): Collection { return $this->convocations; }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }
}
