<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\ReunionDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ReunionDocument — fichier attaché à une réunion (PDF, docx, xlsx, image, etc.).
 *
 * Utilisé pour archiver : ODJ détaillé en PDF, présentation diapos, tableau Excel
 * du budget discuté, photos de la séance, etc. Tout ce que les convoqués peuvent
 * télécharger pour préparer ou suivre la réunion.
 *
 * MULTI-TENANT : délégué via $this->reunion->getClub().
 *
 * STOCKAGE : public/uploads/reunions/{reunionId}/{filename}
 * Le filename généré côté service est un uniqid → impossible à deviner.
 * Sert via une route Symfony avec ClubVoter (anti-fuite).
 */
#[ORM\Entity(repositoryClass: ReunionDocumentRepository::class)]
#[ORM\Table(name: 'reunion_document')]
#[ORM\Index(name: 'idx_rd_reunion', columns: ['reunion_id'])]
#[ORM\HasLifecycleCallbacks]
class ReunionDocument implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reunion::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reunion $reunion = null;

    /**
     * Nom ORIGINAL du fichier (côté user). Affiché dans l'UI.
     * Sépare du `path` (nom stocké sur disque) pour pas exposer notre format interne.
     */
    #[ORM\Column(length: 255)]
    private ?string $nomOriginal = null;

    /**
     * Chemin RELATIF dans public/uploads/reunions/{reunionId}/.
     * Format : "{uniqid}.{ext}" — uniqid évite path traversal et collisions.
     */
    #[ORM\Column(length: 255)]
    private ?string $path = null;

    /**
     * MIME type validé à l'upload (application/pdf, application/vnd.openxmlformats-...).
     * Stocké pour servir le bon Content-Type au téléchargement.
     */
    #[ORM\Column(length: 100)]
    private ?string $mimeType = null;

    /**
     * Taille en octets. Stockée pour l'affichage humain (Mo/Ko) sans relire le disque.
     */
    #[ORM\Column]
    private int $taille = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadePar = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getClub(): ?Club
    {
        return $this->reunion?->getClub();
    }

    // ==================== Getters / Setters ====================

    public function getId(): ?int { return $this->id; }

    public function getReunion(): ?Reunion { return $this->reunion; }
    public function setReunion(?Reunion $r): static { $this->reunion = $r; return $this; }

    public function getNomOriginal(): ?string { return $this->nomOriginal; }
    public function setNomOriginal(string $nom): static { $this->nomOriginal = $nom; return $this; }

    public function getPath(): ?string { return $this->path; }
    public function setPath(string $path): static { $this->path = $path; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(string $mime): static { $this->mimeType = $mime; return $this; }

    public function getTaille(): int { return $this->taille; }
    public function setTaille(int $t): static { $this->taille = $t; return $this; }

    public function getUploadePar(): ?User { return $this->uploadePar; }
    public function setUploadePar(?User $u): static { $this->uploadePar = $u; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * Helper UI : taille humaine (Mo / Ko).
     */
    public function getTailleHumaine(): string
    {
        if ($this->taille >= 1_048_576) return round($this->taille / 1_048_576, 1) . ' Mo';
        if ($this->taille >= 1024) return round($this->taille / 1024) . ' Ko';
        return $this->taille . ' o';
    }

    /**
     * Helper UI : icône Bootstrap selon le type de fichier.
     */
    public function getIcone(): string
    {
        return match (true) {
            str_starts_with($this->mimeType ?? '', 'image/')       => 'bi-image',
            $this->mimeType === 'application/pdf'                  => 'bi-file-pdf-fill',
            str_contains($this->mimeType ?? '', 'word')            => 'bi-file-word-fill',
            str_contains($this->mimeType ?? '', 'sheet'),
            str_contains($this->mimeType ?? '', 'excel')           => 'bi-file-excel-fill',
            str_contains($this->mimeType ?? '', 'presentation')    => 'bi-file-ppt-fill',
            default                                                => 'bi-file-earmark',
        };
    }
}
