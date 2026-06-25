<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\ContenuSeanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ContenuSeance — fiche pédagogique d'une séance de basketball.
 *
 * Séparée de l'entité Seance (qui gère la logique présence/planning)
 * pour permettre une bibliothèque réutilisable : un même contenu peut
 * être attaché à plusieurs séances récurrentes.
 *
 * Workflow :
 *   1. Coach crée une fiche dans la bibliothèque (/contenus-seances/nouveau)
 *   2. Il peut l'attacher à une Seance existante (Seance::contenuSeance FK)
 *   3. Si isPublicClub = true, les autres coachs du club voient et réutilisent la fiche
 *
 * V1  : titre + description + catégories + thèmes + fichiers (photos/PDFs)
 * V2  : exercices interactifs, bibliothèque collaborative, import communautaire
 */
#[ORM\Entity(repositoryClass: ContenuSeanceRepository::class)]
#[ORM\Table(name: 'contenu_seance')]
#[ORM\HasLifecycleCallbacks]
class ContenuSeance
{
    /** Catégories d'âge disponibles */
    public const CATEGORIES_AGE = ['U11', 'U13', 'U15', 'U17', 'U20', 'Seniors'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $createdBy = null;

    // ─── Contenu pédagogique ──────────────────────────────────────────────

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(max: 150)]
    private string $titre = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Catégories d'âge cibles — JSON array : ['U13', 'U15']
     * Stocké en JSON string, exposé comme array PHP.
     */
    #[ORM\Column(type: 'json')]
    private array $categoriesAge = [];

    /**
     * Fichiers joints — JSON array :
     * [{'type':'pdf'|'photo', 'path':'...', 'originalName':'...', 'size':12345}]
     */
    #[ORM\Column(type: 'json')]
    private array $fichiers = [];

    // ─── Thèmes (ManyToMany) ─────────────────────────────────────────────

    /** @var Collection<int, ThemeSeance> */
    #[ORM\ManyToMany(targetEntity: ThemeSeance::class)]
    #[ORM\JoinTable(name: 'contenu_seance_theme')]
    private Collection $themes;

    // ─── Visibilité ───────────────────────────────────────────────────────

    /**
     * Si true → visible par tous les coachs du club.
     * Si false → privé, visible uniquement par createdBy.
     */
    #[ORM\Column]
    private bool $isPublicClub = true;

    // ─── Timestamps ───────────────────────────────────────────────────────

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

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

    public function __construct()
    {
        $this->themes = new ArrayCollection();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $c): self { $this->club = $c; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $t): self { $this->titre = $t; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): self { $this->description = $d; return $this; }

    public function getCategoriesAge(): array { return $this->categoriesAge; }
    public function setCategoriesAge(array $c): self { $this->categoriesAge = $c; return $this; }

    public function getFichiers(): array { return $this->fichiers; }
    public function setFichiers(array $f): self { $this->fichiers = $f; return $this; }

    public function addFichier(string $type, string $path, string $originalName, int $size = 0): self
    {
        $this->fichiers[] = [
            'type'         => $type,
            'path'         => $path,
            'originalName' => $originalName,
            'size'         => $size,
        ];
        return $this;
    }

    public function removeFichier(string $path): self
    {
        $this->fichiers = array_values(array_filter(
            $this->fichiers,
            fn($f) => $f['path'] !== $path
        ));
        return $this;
    }

    /**
     * Supprime un fichier par sa position dans le tableau JSON.
     * Utilisé par le controller pour l'action de suppression unitaire.
     * array_values() ré-indexe pour éviter les trous dans les index.
     */
    public function removeFichierByIndex(int $index): self
    {
        if (isset($this->fichiers[$index])) {
            array_splice($this->fichiers, $index, 1);
            $this->fichiers = array_values($this->fichiers);
        }
        return $this;
    }

    /** @return Collection<int, ThemeSeance> */
    public function getThemes(): Collection { return $this->themes; }

    public function addTheme(ThemeSeance $t): self
    {
        if (!$this->themes->contains($t)) { $this->themes->add($t); }
        return $this;
    }

    public function removeTheme(ThemeSeance $t): self
    {
        $this->themes->removeElement($t);
        return $this;
    }

    public function isPublicClub(): bool { return $this->isPublicClub; }
    public function setIsPublicClub(bool $b): self { $this->isPublicClub = $b; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Retourne les thèmes groupés par groupe pour l'affichage.
     *
     * @return array<string, ThemeSeance[]>
     */
    public function getThemesParGroupe(): array
    {
        $grouped = [];
        foreach ($this->themes as $theme) {
            $grouped[$theme->getGroupe()][] = $theme;
        }
        return $grouped;
    }

    /**
     * Retourne les fichiers PDF uniquement.
     */
    public function getFichiersPdf(): array
    {
        return array_values(array_filter($this->fichiers, fn($f) => $f['type'] === 'pdf'));
    }

    /**
     * Retourne les fichiers photo uniquement.
     */
    public function getFichiersPhoto(): array
    {
        return array_values(array_filter($this->fichiers, fn($f) => $f['type'] === 'photo'));
    }

    public function __toString(): string { return $this->titre; }
}
