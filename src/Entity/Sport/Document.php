<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\DocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document — ENT (Espace Numérique de Travail) du club.
 *
 * Permet au staff d'uploader des documents classifiés par TYPE,
 * avec une VISIBILITÉ configurable (staff, membres, parents).
 *
 * WORKFLOW :
 *   1. Staff (CLUB_STAFF_ELARGI) uploade un document, choisit un type.
 *   2. La visibilité par défaut est déduite du type (surrideable).
 *   3. Les membres/parents voient les documents selon leur visibilité.
 *
 * TYPES disponibles :
 *   - COMPTE_RENDU    : PV, synthèse réunion → STAFF par défaut
 *   - PLANNING        : calendrier, programme → MEMBRES par défaut
 *   - REGLEMENT       : règlement intérieur, charte → MEMBRES par défaut
 *   - FORMULAIRE      : fiche inscriptions, autorisations → PARENTS par défaut
 *   - DOCUMENT_JOUEUR : fiche joueuse, licence → PARENTS par défaut
 *   - MEDIA           : photos, vidéos → MEMBRES par défaut
 *   - CONVOCATION     : convocations officielles → MEMBRES par défaut
 *   - AUTRE           : tout le reste → STAFF par défaut
 *
 * VISIBILITÉ :
 *   - STAFF    : CLUB_STAFF_ELARGI uniquement (coach, dirigeant, staff)
 *   - MEMBRES  : tous les CLUB_MEMBER (joueurs + staff + parents inscrits)
 *   - PARENTS  : parents (PIRB) + staff (non visible aux joueurs non liés)
 *
 * MULTI-TENANT : appartient directement à un Club (ClubAwareInterface).
 * STOCKAGE     : public/uploads/ent/{clubId}/{uniqid}.{ext}
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_doc_club', columns: ['club_id'])]
#[ORM\Index(name: 'idx_doc_type', columns: ['type'])]
#[ORM\Index(name: 'idx_doc_visibilite', columns: ['visibilite'])]
#[ORM\HasLifecycleCallbacks]
class Document implements ClubAwareInterface
{
    // ====================================================================
    // TYPES
    // ====================================================================
    public const TYPE_COMPTE_RENDU    = 'COMPTE_RENDU';
    public const TYPE_PLANNING        = 'PLANNING';
    public const TYPE_REGLEMENT       = 'REGLEMENT';
    public const TYPE_FORMULAIRE      = 'FORMULAIRE';
    public const TYPE_DOCUMENT_JOUEUR = 'DOCUMENT_JOUEUR';
    public const TYPE_MEDIA           = 'MEDIA';
    public const TYPE_CONVOCATION     = 'CONVOCATION';
    public const TYPE_AUTRE           = 'AUTRE';

    public const TYPES = [
        self::TYPE_COMPTE_RENDU,
        self::TYPE_PLANNING,
        self::TYPE_REGLEMENT,
        self::TYPE_FORMULAIRE,
        self::TYPE_DOCUMENT_JOUEUR,
        self::TYPE_MEDIA,
        self::TYPE_CONVOCATION,
        self::TYPE_AUTRE,
    ];

    public const TYPE_LIBELLES = [
        self::TYPE_COMPTE_RENDU    => 'Compte-rendu / PV',
        self::TYPE_PLANNING        => 'Planning / Calendrier',
        self::TYPE_REGLEMENT       => 'Règlement / Charte',
        self::TYPE_FORMULAIRE      => 'Formulaire / Autorisation',
        self::TYPE_DOCUMENT_JOUEUR => 'Document joueuse',
        self::TYPE_MEDIA           => 'Photo / Média',
        self::TYPE_CONVOCATION     => 'Convocation',
        self::TYPE_AUTRE           => 'Autre',
    ];

    // ====================================================================
    // VISIBILITÉ
    // ====================================================================
    public const VIS_STAFF   = 'STAFF';    // CLUB_STAFF_ELARGI uniquement
    public const VIS_MEMBRES = 'MEMBRES';  // Tous les CLUB_MEMBER
    public const VIS_PARENTS = 'PARENTS';  // Parents (PIRB) + staff

    public const VISIBILITES = [
        self::VIS_STAFF,
        self::VIS_MEMBRES,
        self::VIS_PARENTS,
    ];

    public const VISIBILITE_LIBELLES = [
        self::VIS_STAFF   => 'Staff uniquement',
        self::VIS_MEMBRES => 'Tous les membres',
        self::VIS_PARENTS => 'Parents & Staff',
    ];

    /**
     * Visibilité par défaut selon le type.
     * Surchargeable à l'upload.
     */
    public const TYPE_VISIBILITE_DEFAUT = [
        self::TYPE_COMPTE_RENDU    => self::VIS_STAFF,
        self::TYPE_PLANNING        => self::VIS_MEMBRES,
        self::TYPE_REGLEMENT       => self::VIS_MEMBRES,
        self::TYPE_FORMULAIRE      => self::VIS_PARENTS,
        self::TYPE_DOCUMENT_JOUEUR => self::VIS_PARENTS,
        self::TYPE_MEDIA           => self::VIS_MEMBRES,
        self::TYPE_CONVOCATION     => self::VIS_MEMBRES,
        self::TYPE_AUTRE           => self::VIS_STAFF,
    ];

    // ====================================================================
    // CHAMPS
    // ====================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Club propriétaire (multi-tenant). */
    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Titre affiché dans l'ENT.
     * Ex: "Compte-rendu CA du 12/05/2026", "Planning mars 2026".
     */
    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    /** Type fonctionnel du document (voir constantes TYPE_*). */
    #[ORM\Column(length: 50)]
    private ?string $type = null;

    /**
     * Qui peut voir ce document (voir constantes VIS_*).
     * Déduit du type à la création, surchargeable.
     */
    #[ORM\Column(length: 20)]
    private string $visibilite = self::VIS_STAFF;

    /**
     * Description optionnelle (contexte, instructions pour les parents, etc.).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Nom ORIGINAL du fichier (affiché dans l'UI, jamais utilisé pour stocker).
     */
    #[ORM\Column(length: 255)]
    private ?string $nomOriginal = null;

    /**
     * Chemin RELATIF dans public/uploads/ent/{clubId}/.
     * Format : "{uniqid}.{ext}" — uniqid évite path traversal et collisions.
     */
    #[ORM\Column(length: 255)]
    private ?string $path = null;

    /** MIME type validé à l'upload (pour servir le bon Content-Type). */
    #[ORM\Column(length: 100)]
    private ?string $mimeType = null;

    /** Taille en octets (stockée pour affichage sans relire le disque). */
    #[ORM\Column]
    private int $taille = 0;

    /**
     * Joueur optionnellement lié (si document concerne une joueuse spécifique).
     * Ex: fiche médicale individuelle, licence d'une joueuse.
     */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    /** Qui a uploadé ce document. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadePar = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    // ====================================================================
    // LIFECYCLE
    // ====================================================================

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // ClubAwareInterface
    // ====================================================================

    public function getClub(): ?Club
    {
        return $this->club;
    }

    // ====================================================================
    // HELPERS UI
    // ====================================================================

    /** Libellé humain du type. */
    public function getTypeLibelle(): string
    {
        return self::TYPE_LIBELLES[$this->type] ?? $this->type ?? '?';
    }

    /** Libellé humain de la visibilité. */
    public function getVisibiliteLibelle(): string
    {
        return self::VISIBILITE_LIBELLES[$this->visibilite] ?? $this->visibilite;
    }

    /** Taille humaine (Mo / Ko). */
    public function getTailleHumaine(): string
    {
        if ($this->taille >= 1_048_576) return round($this->taille / 1_048_576, 1) . ' Mo';
        if ($this->taille >= 1024)      return round($this->taille / 1024) . ' Ko';
        return $this->taille . ' o';
    }

    /** Icône Bootstrap selon le MIME type. */
    public function getIcone(): string
    {
        return match (true) {
            str_starts_with($this->mimeType ?? '', 'image/')    => 'bi-image',
            $this->mimeType === 'application/pdf'               => 'bi-file-pdf-fill',
            str_contains($this->mimeType ?? '', 'word')         => 'bi-file-word-fill',
            str_contains($this->mimeType ?? '', 'sheet'),
            str_contains($this->mimeType ?? '', 'excel')        => 'bi-file-excel-fill',
            str_contains($this->mimeType ?? '', 'presentation') => 'bi-file-ppt-fill',
            default                                             => 'bi-file-earmark',
        };
    }

    /**
     * Couleur badge visibilité (Bootstrap / inline style).
     */
    public function getVisibiliteBadgeClass(): string
    {
        return match ($this->visibilite) {
            self::VIS_STAFF   => 'bg-danger',
            self::VIS_MEMBRES => 'bg-success',
            self::VIS_PARENTS => 'bg-info',
            default           => 'bg-secondary',
        };
    }

    /**
     * Couleur badge type (inline style hex).
     */
    public function getTypeBadgeColor(): string
    {
        return match ($this->type) {
            self::TYPE_COMPTE_RENDU    => '#7c3aed',
            self::TYPE_PLANNING        => '#0891b2',
            self::TYPE_REGLEMENT       => '#dc2626',
            self::TYPE_FORMULAIRE      => '#ea580c',
            self::TYPE_DOCUMENT_JOUEUR => '#ec4899',
            self::TYPE_MEDIA           => '#16a34a',
            self::TYPE_CONVOCATION     => '#2563eb',
            default                    => '#6b7280',
        };
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getTitre(): ?string { return $this->titre; }
    public function setTitre(string $titre): static { $this->titre = $titre; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getVisibilite(): string { return $this->visibilite; }
    public function setVisibilite(string $visibilite): static { $this->visibilite = $visibilite; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getNomOriginal(): ?string { return $this->nomOriginal; }
    public function setNomOriginal(string $nom): static { $this->nomOriginal = $nom; return $this; }

    public function getPath(): ?string { return $this->path; }
    public function setPath(string $path): static { $this->path = $path; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(string $mime): static { $this->mimeType = $mime; return $this; }

    public function getTaille(): int { return $this->taille; }
    public function setTaille(int $t): static { $this->taille = $t; return $this; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getUploadePar(): ?User { return $this->uploadePar; }
    public function setUploadePar(?User $u): static { $this->uploadePar = $u; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
