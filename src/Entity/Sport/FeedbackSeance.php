<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\FeedbackSeanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * B9 — Feedback PIRB sur une Séance (note 0-5 + commentaire + anonyme).
 *
 * RÈGLE D'ANONYMAT :
 *   - Si est_anonyme = true → on STOCKE quand même joueur_id pour le
 *     compteur anti-doublon, mais l'UI Manager **NE le révèle jamais**.
 *     Le coach voit "Note 3/5 anonyme" sans savoir qui.
 *   - Si est_anonyme = false → joueur_id visible côté coach (vote signé).
 *
 * Anti-doublon :
 *   - Mode signé : 1 vote par joueuse par séance (vérifié côté controller)
 *   - Mode anonyme : 1 vote autorisé aussi (pas de spam) — vérifié pareil
 *
 * Gamification : un joueur qui poste 10 feedbacks gagne le badge A_RETEX_REGULIER.
 */
#[ORM\Entity(repositoryClass: FeedbackSeanceRepository::class)]
#[ORM\Table(name: 'feedback_seance')]
class FeedbackSeance
{
    public const NOTE_MIN = 0;
    public const NOTE_MAX = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Seance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    /**
     * NULL côté Manager UI quand est_anonyme = true (le coach ne sait pas qui).
     * En BDD c'est toujours renseigné pour anti-doublon technique.
     */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: self::NOTE_MIN, max: self::NOTE_MAX)]
    private int $note = 3;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private bool $estAnonyme = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): self { $this->seance = $s; return $this; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getNote(): int { return $this->note; }
    public function setNote(int $n): self { $this->note = max(self::NOTE_MIN, min(self::NOTE_MAX, $n)); return $this; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $c): self { $this->commentaire = $c; return $this; }
    public function isAnonyme(): bool { return $this->estAnonyme; }
    public function setEstAnonyme(bool $a): self { $this->estAnonyme = $a; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
