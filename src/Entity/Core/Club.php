<?php

namespace App\Entity\Core;

use App\Repository\Core\ClubRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClubRepository::class)]
#[ORM\Table(name: 'club')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé.')]
class Club
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    /**
     * Sigle / acronyme court du club.
     * Ex: "MABB", "ASVEL", "JSF"…
     * Nullable : les anciens clubs n'en ont pas encore.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $sigle = null;

    /**
     * Slug unique : identifiant URL du club.
     * Ex: "mabb", "amiens-basket", etc.
     * Utilisé pour le routing multi-tenant.
     */
    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9\-]+$/', message: 'Le slug ne doit contenir que des lettres minuscules, chiffres et tirets.')]
    private ?string $slug = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteWeb = null;

    // ── Multi-club : création & officialisation ──────────────────────────
    public const DISCIPLINE_FEMININ  = 'feminin';
    public const DISCIPLINE_MASCULIN = 'masculin';
    public const DISCIPLINE_MIXTE    = 'mixte';
    public const DISCIPLINES = [self::DISCIPLINE_FEMININ, self::DISCIPLINE_MASCULIN, self::DISCIPLINE_MIXTE];
    public const DISCIPLINE_LIBELLES = [
        self::DISCIPLINE_FEMININ  => 'Basket féminin',
        self::DISCIPLINE_MASCULIN => 'Basket masculin',
        self::DISCIPLINE_MIXTE    => 'Mixte',
    ];

    public const PLAN_DECOUVERTE = 'decouverte';
    public const PLAN_CLUB       = 'club';
    public const PLAN_PREMIUM    = 'premium';

    /** Discipline du club (féminin / masculin / mixte). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $discipline = null;

    /**
     * N° d'agrément / groupement FFBB (ex. « HDF0080036 »). Nullable.
     * Unique quand renseigné (MySQL autorise plusieurs NULL) : anti-doublon /
     * anti-imposteur — un même numéro ne peut être revendiqué qu'une seule fois.
     */
    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $numeroFfbb = null;

    /**
     * OFFICIEL = numeroFfbb correspond à un OrganismeFfbb du référentiel FFBB.
     * Posé à la validation. Défaut : non-officiel. Officiel/non-officiel ont
     * les MÊMES fonctionnalités (décision produit) — c'est juste un badge + la
     * protection anti-doublon.
     */
    #[ORM\Column]
    private bool $isOfficiel = false;

    /** Plan d'abonnement (facturation non implémentée). Défaut : Découverte. */
    #[ORM\Column(length: 20, options: ['default' => self::PLAN_DECOUVERTE])]
    private string $plan = self::PLAN_DECOUVERTE;

    /** Créateur du club → admin auto de son club. Nullable (clubs historiques). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createur = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Tous les UserClubRole liés à ce club */
    #[ORM\OneToMany(targetEntity: UserClubRole::class, mappedBy: 'club', cascade: ['persist', 'remove'])]
    private Collection $userClubRoles;

    public function __construct()
    {
        $this->userClubRoles = new ArrayCollection();
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

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getSigle(): ?string
    {
        return $this->sigle;
    }

    public function setSigle(?string $sigle): static
    {
        $this->sigle = $sigle !== null ? strtoupper(trim($sigle)) : null;
        return $this;
    }

    /**
     * Retourne le sigle si défini, sinon les initiales du nom (fallback).
     * Ex: "Amiens Métropole Basket-Ball" → "AMBB" si pas de sigle renseigné.
     */
    public function getSigleOuInitiales(): string
    {
        if ($this->sigle !== null) {
            return $this->sigle;
        }
        // Fallback : premières lettres de chaque mot capitalisé
        $mots = preg_split('/\s+/', $this->nom ?? '');
        return implode('', array_map(
            static fn($m) => mb_strtoupper(mb_substr($m, 0, 1)),
            array_filter($mots, static fn($m) => mb_strlen($m) > 2)
        )) ?: ($this->nom ?? '');
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $ville;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(?string $codePostal): static
    {
        $this->codePostal = $codePostal;
        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
        return $this;
    }

    public function getSiteWeb(): ?string
    {
        return $this->siteWeb;
    }

    public function setSiteWeb(?string $siteWeb): static
    {
        $this->siteWeb = $siteWeb;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ── Multi-club : discipline / FFBB / officiel / plan / créateur ───────

    public function getDiscipline(): ?string { return $this->discipline; }
    public function setDiscipline(?string $discipline): static { $this->discipline = $discipline; return $this; }
    public function getDisciplineLibelle(): ?string
    {
        return $this->discipline !== null ? (self::DISCIPLINE_LIBELLES[$this->discipline] ?? $this->discipline) : null;
    }

    public function getNumeroFfbb(): ?string { return $this->numeroFfbb; }
    public function setNumeroFfbb(?string $numeroFfbb): static
    {
        // Normalisation : majuscules + trim, ou null si vide (cohérent avec OrganismeFfbb).
        $numeroFfbb = $numeroFfbb !== null ? strtoupper(trim($numeroFfbb)) : null;
        $this->numeroFfbb = ($numeroFfbb === '') ? null : $numeroFfbb;
        return $this;
    }

    public function isOfficiel(): bool { return $this->isOfficiel; }
    public function setIsOfficiel(bool $isOfficiel): static { $this->isOfficiel = $isOfficiel; return $this; }

    public function getPlan(): string { return $this->plan; }
    public function setPlan(string $plan): static { $this->plan = $plan; return $this; }

    public function getCreateur(): ?User { return $this->createur; }
    public function setCreateur(?User $createur): static { $this->createur = $createur; return $this; }

    /** @return Collection<int, UserClubRole> */
    public function getUserClubRoles(): Collection
    {
        return $this->userClubRoles;
    }

    public function addUserClubRole(UserClubRole $userClubRole): static
    {
        if (!$this->userClubRoles->contains($userClubRole)) {
            $this->userClubRoles->add($userClubRole);
            $userClubRole->setClub($this);
        }
        return $this;
    }

    public function removeUserClubRole(UserClubRole $userClubRole): static
    {
        $this->userClubRoles->removeElement($userClubRole);
        return $this;
    }
}
