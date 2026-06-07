<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\ReunionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Reunion — réunion du bureau d'un club (CA, AG, bureau restreint, etc.).
 *
 * WORKFLOW :
 *   1. STATUT_PLANIFIE : créée mais pas encore tenue. Membres convoqués.
 *   2. STATUT_TENUE    : a eu lieu. Le PV peut être saisi.
 *   3. STATUT_ANNULEE  : annulée (raison dans le PV ou non).
 *
 * MULTI-TENANT : appartient à UN club via ClubAwareInterface.
 *
 * Une réunion a une collection de ReunionConvocation : qui doit/devait être présent.
 * Les membres convoqués verront un badge sur leur dashboard Manager.
 */
#[ORM\Entity(repositoryClass: ReunionRepository::class)]
#[ORM\Table(name: 'reunion')]
#[ORM\HasLifecycleCallbacks]
class Reunion implements ClubAwareInterface
{
    // ====================================================================
    // TYPES — selon le règlement loi 1901
    // ====================================================================
    public const TYPE_CA              = 'CA';                 // Conseil d'administration
    public const TYPE_AG_ORDINAIRE    = 'AG_ORDINAIRE';       // Assemblée générale ordinaire (1×/an)
    public const TYPE_AG_EXTRAORDINAIRE = 'AG_EXTRAORDINAIRE'; // AG extraordinaire (modif statuts, dissolution...)
    public const TYPE_BUREAU          = 'BUREAU';             // Bureau restreint (président + trésorier + secrétaire)
    public const TYPE_AUTRE           = 'AUTRE';              // Réunion d'équipe, commission, etc.

    public const TYPES = [
        self::TYPE_CA,
        self::TYPE_AG_ORDINAIRE,
        self::TYPE_AG_EXTRAORDINAIRE,
        self::TYPE_BUREAU,
        self::TYPE_AUTRE,
    ];

    /** Libellés humains pour l'UI */
    public const TYPE_LIBELLES = [
        self::TYPE_CA               => 'Conseil d\'administration',
        self::TYPE_AG_ORDINAIRE     => 'Assemblée générale ordinaire',
        self::TYPE_AG_EXTRAORDINAIRE => 'Assemblée générale extraordinaire',
        self::TYPE_BUREAU           => 'Bureau restreint',
        self::TYPE_AUTRE            => 'Autre réunion',
    ];

    // ====================================================================
    // STATUTS
    // ====================================================================
    public const STATUT_PLANIFIE = 'planifie';
    public const STATUT_TENUE    = 'tenue';
    public const STATUT_ANNULEE  = 'annulee';

    public const STATUTS = [self::STATUT_PLANIFIE, self::STATUT_TENUE, self::STATUT_ANNULEE];

    // ====================================================================
    // CHAMPS
    // ====================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    private ?string $titre = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = self::TYPE_CA;

    #[ORM\Column]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $lieu = null;

    /**
     * Ordre du jour — texte structuré (1 point par ligne ou markdown léger).
     */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private ?string $ordreDuJour = null;

    /**
     * Compte-rendu / PV — saisi APRÈS la réunion (nullable au départ).
     * DÉTAIL : visible uniquement par les convoqués (ReunionConvocation).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pvContenu = null;

    /**
     * Synthèse PUBLIQUE de la réunion — version courte/diffusable.
     * Distincte du PV (qui contient les détails, débats, votes nominatifs).
     * Saisie par le secrétaire/président, publiée à TOUS les CLUB_MEMBER quand flag à true.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $synthesePublique = null;

    /**
     * Liste des rôles métier autorisés à voir la synthèse publique.
     *
     * Sélecteur granulaire (Phase F.2) :
     *   - NULL ou []                        → non publiée (staff seul)
     *   - ['DIRIGEANT', 'COACH']            → visible aux dirigeants + coachs
     *   - ['PARENT', 'JOUEUR']              → annonce parents + joueuses
     *   - tous les rôles                    → équivalent "publiée à tout le club"
     *
     * Permet de cibler : "info bureau seulement", "annonce parents",
     * "communication joueuses", etc.
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $syntheseVisibleRoles = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS)]
    private string $statut = self::STATUT_PLANIFIE;

    /**
     * Auteur de la création de la réunion (souvent secrétaire ou président).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Convocations envoyées (qui devait/doit être présent + statut individuel).
     * @var Collection<int, ReunionConvocation>
     */
    #[ORM\OneToMany(targetEntity: ReunionConvocation::class, mappedBy: 'reunion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $convocations;

    /**
     * Fichiers attachés (PDF, docx, images...).
     * @var Collection<int, ReunionDocument>
     */
    #[ORM\OneToMany(targetEntity: ReunionDocument::class, mappedBy: 'reunion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $documents;

    /**
     * Versions précédentes du PV (audit trail des modifications).
     * @var Collection<int, ReunionPvVersion>
     */
    #[ORM\OneToMany(targetEntity: ReunionPvVersion::class, mappedBy: 'reunion', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pvVersions;

    public function __construct()
    {
        $this->convocations = new ArrayCollection();
        $this->documents    = new ArrayCollection();
        $this->pvVersions   = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // MULTI-TENANT
    // ====================================================================

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(string $titre): static { $this->titre = $titre; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getTypeLibelle(): string { return self::TYPE_LIBELLES[$this->type] ?? $this->type; }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(?string $lieu): static { $this->lieu = $lieu; return $this; }

    public function getOrdreDuJour(): ?string { return $this->ordreDuJour; }
    public function setOrdreDuJour(string $odj): static { $this->ordreDuJour = $odj; return $this; }

    public function getPvContenu(): ?string { return $this->pvContenu; }
    public function setPvContenu(?string $pv): static { $this->pvContenu = $pv; return $this; }
    public function hasPv(): bool { return $this->pvContenu !== null && trim($this->pvContenu) !== ''; }

    public function getSynthesePublique(): ?string { return $this->synthesePublique; }
    public function setSynthesePublique(?string $s): static { $this->synthesePublique = $s; return $this; }
    public function hasSynthese(): bool { return $this->synthesePublique !== null && trim($this->synthesePublique) !== ''; }

    /** @return string[] */
    public function getSyntheseVisibleRoles(): array { return $this->syntheseVisibleRoles ?? []; }
    public function setSyntheseVisibleRoles(?array $roles): static { $this->syntheseVisibleRoles = $roles; return $this; }

    /**
     * La synthèse est-elle publiée (= au moins un rôle a accès) ?
     * Helper sémantique pour remplacer l'ancien isSynthesePubliee().
     */
    public function isSynthesePubliee(): bool
    {
        return !empty($this->syntheseVisibleRoles) && $this->hasSynthese();
    }

    /**
     * Un user a-t-il accès à la synthèse via au moins un de ses rôles dans le club ?
     *
     * @param string[] $userRoles Liste des rôles actifs de l'user dans CE club
     */
    public function syntheseVisibleA(array $userRoles): bool
    {
        if (!$this->isSynthesePubliee()) return false;
        return !empty(array_intersect($userRoles, $this->syntheseVisibleRoles ?? []));
    }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    public function isPlanifiee(): bool { return $this->statut === self::STATUT_PLANIFIE; }
    public function isTenue(): bool { return $this->statut === self::STATUT_TENUE; }
    public function isAnnulee(): bool { return $this->statut === self::STATUT_ANNULEE; }

    public function getCreateur(): ?User { return $this->createur; }
    public function setCreateur(?User $u): static { $this->createur = $u; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, ReunionConvocation> */
    public function getConvocations(): Collection { return $this->convocations; }

    /** @return Collection<int, ReunionDocument> */
    public function getDocuments(): Collection { return $this->documents; }

    /** @return Collection<int, ReunionPvVersion> */
    public function getPvVersions(): Collection { return $this->pvVersions; }

    public function addConvocation(ReunionConvocation $c): static
    {
        if (!$this->convocations->contains($c)) {
            $this->convocations->add($c);
            $c->setReunion($this);
        }
        return $this;
    }

    public function removeConvocation(ReunionConvocation $c): static
    {
        if ($this->convocations->removeElement($c)) {
            if ($c->getReunion() === $this) {
                $c->setReunion(null);
            }
        }
        return $this;
    }
}
