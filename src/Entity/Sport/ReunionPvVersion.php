<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\ReunionPvVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ReunionPvVersion — snapshot du contenu du PV à chaque modification majeure.
 *
 * Objectif Clavel : "avoir un suivi de réunion pas qu'on fasse puis ce qui a été dit
 * disparait". On ne supprime jamais hard — on archive.
 *
 * RÈGLE : à chaque fois que le PV est modifié ET que la nouvelle valeur est
 * différente de l'ancienne, on crée un snapshot AVANT d'écraser. Permet de
 * remonter dans l'historique : "qui a écrit quoi, quand".
 *
 * STOCKAGE : on stocke le contenu intégral à chaque version (pas de diff).
 * Texte donc espace négligeable, simplicité de lecture.
 *
 * MULTI-TENANT : via $this->reunion->getClub().
 */
#[ORM\Entity(repositoryClass: ReunionPvVersionRepository::class)]
#[ORM\Table(name: 'reunion_pv_version')]
#[ORM\Index(name: 'idx_rpvv_reunion_date', columns: ['reunion_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class ReunionPvVersion implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reunion::class, inversedBy: 'pvVersions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Reunion $reunion = null;

    /**
     * Contenu intégral du PV à ce moment-là (snapshot complet).
     * Stocker en clair (pas de diff) est plus simple à lire et l'espace texte
     * est négligeable. Pour 100 réunions × 5 versions × 5000 char = 2.5 Mo.
     */
    #[ORM\Column(type: 'text')]
    private ?string $contenuSnapshot = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

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

    public function getId(): ?int { return $this->id; }

    public function getReunion(): ?Reunion { return $this->reunion; }
    public function setReunion(?Reunion $r): static { $this->reunion = $r; return $this; }

    public function getContenuSnapshot(): ?string { return $this->contenuSnapshot; }
    public function setContenuSnapshot(string $contenu): static { $this->contenuSnapshot = $contenu; return $this; }

    public function getModifiePar(): ?User { return $this->modifiePar; }
    public function setModifiePar(?User $u): static { $this->modifiePar = $u; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
