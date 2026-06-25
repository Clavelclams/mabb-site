<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\NoteSeanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * NoteSeance — feedback anonyme d'une joueuse sur une séance passée.
 *
 * Anonymat :
 *   Le coach VOIT les statistiques agrégées (moyenne, distribution des notes,
 *   commentaires listés) mais PAS qui a mis quelle note — l'anonymat encourage
 *   l'honnêteté et améliore la qualité du feedback.
 *
 * Règles :
 *   - Une joueuse ne peut noter une séance QUE si le coach a marqué sa présence
 *   - Une note par joueuse par séance (UNIQUE)
 *   - Modifiable tant que la séance date de moins de 7 jours
 */
#[ORM\Entity(repositoryClass: NoteSeanceRepository::class)]
#[ORM\Table(name: 'note_seance')]
#[ORM\UniqueConstraint(name: 'UNQ_NS_JOUEUR_SEANCE', columns: ['joueur_id', 'seance_id'])]
#[ORM\HasLifecycleCallbacks]
class NoteSeance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Seance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    /**
     * Note de ressenti 1 à 5 étoiles.
     * 1 = séance difficile / peu motivante
     * 5 = séance excellente / très motivante
     */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'La note doit être entre 1 et 5.')]
    private int $note = 3;

    /**
     * Commentaire libre optionnel.
     * Affiché au coach de façon anonyme dans le dashboard feedback.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 500, maxMessage: '500 caractères max.')]
    private ?string $commentaire = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    // ─── Getters / Setters ────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): self { $this->seance = $s; return $this; }
    public function getNote(): int { return $this->note; }
    public function setNote(int $n): self { $this->note = max(1, min(5, $n)); return $this; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $c): self { $this->commentaire = $c; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    /** Emoji correspondant à la note */
    public function getEmoji(): string
    {
        return match ($this->note) {
            1 => '😞',
            2 => '😕',
            3 => '😐',
            4 => '😊',
            5 => '🔥',
            default => '⭐',
        };
    }
}
