<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\DemandeAccesPdfRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B22a-sec] Système de demande d'accès aux PDFs officiels FFBB côté joueuse.
 *
 * Problème : Willy ne veut pas que les joueuses téléchargent directement les
 * PDFs officiels FFBB (feuille de match, résumé stats, positions de tirs).
 * Ces documents contiennent des données sensibles sur TOUTE l'équipe.
 *
 * Solution :
 *   1. La joueuse clique sur "Demander l'accès"
 *   2. Une DemandeAccesPdf (statut=pending) est créée
 *   3. Le coach reçoit une notification dans Manager
 *   4. Il approuve → la joueuse peut télécharger (statut=approved)
 *   5. Il refuse → la joueuse voit "refusé par ton coach" (statut=rejected)
 *
 * Exception : les stats individuelles (EvaluationFfbb) restent accessibles
 * directement — seuls les PDFs bruts FFBB sont bloqués.
 *
 * UNIQUE(joueur, rencontre, type_pdf) : une seule demande active par document.
 * Si rejected, on peut re-demander (on met à jour la ligne existante).
 */
#[ORM\Entity(repositoryClass: DemandeAccesPdfRepository::class)]
#[ORM\Table(name: 'demande_acces_pdf')]
#[ORM\UniqueConstraint(name: 'UNQ_DAP_JOUEUR_REN_TYPE', columns: ['joueur_id', 'rencontre_id', 'type_pdf'])]
#[ORM\HasLifecycleCallbacks]
class DemandeAccesPdf
{
    public const STATUT_PENDING  = 'pending';
    public const STATUT_APPROVED = 'approved';
    public const STATUT_REJECTED = 'rejected';

    public const TYPES = ['feuille', 'resume', 'positions'];

    public const LABELS_TYPE = [
        'feuille'   => 'Feuille de match',
        'resume'    => 'Résumé stats',
        'positions' => 'Positions des tirs',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    /**
     * Type de PDF demandé : feuille | resume | positions
     */
    #[ORM\Column(length: 20)]
    private string $typePdf = 'feuille';

    /**
     * Statut de la demande : pending | approved | rejected
     */
    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PENDING;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Date de la décision (approbation ou refus) par le coach.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /**
     * Coach qui a approuvé ou refusé (nullable si pas encore décidé).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $coach = null;

    /**
     * Message optionnel du coach à la joueuse (ex: "ok pour cette fois").
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $messageCoach = null;

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    // ─── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): self { $this->rencontre = $r; return $this; }

    public function getTypePdf(): string { return $this->typePdf; }
    public function setTypePdf(string $t): self { $this->typePdf = $t; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): self { $this->statut = $s; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function setDecidedAt(?\DateTimeImmutable $d): self { $this->decidedAt = $d; return $this; }

    public function getCoach(): ?User { return $this->coach; }
    public function setCoach(?User $c): self { $this->coach = $c; return $this; }

    public function getMessageCoach(): ?string { return $this->messageCoach; }
    public function setMessageCoach(?string $m): self { $this->messageCoach = $m; return $this; }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isPending(): bool  { return $this->statut === self::STATUT_PENDING; }
    public function isApproved(): bool { return $this->statut === self::STATUT_APPROVED; }
    public function isRejected(): bool { return $this->statut === self::STATUT_REJECTED; }

    public function getLabelType(): string
    {
        return self::LABELS_TYPE[$this->typePdf] ?? $this->typePdf;
    }

    /**
     * Approuver la demande (appelé par le coach).
     */
    public function approuver(User $coach, ?string $message = null): void
    {
        $this->statut      = self::STATUT_APPROVED;
        $this->coach       = $coach;
        $this->decidedAt   = new \DateTimeImmutable();
        $this->messageCoach = $message;
    }

    /**
     * Refuser la demande (appelé par le coach).
     */
    public function refuser(User $coach, ?string $message = null): void
    {
        $this->statut      = self::STATUT_REJECTED;
        $this->coach       = $coach;
        $this->decidedAt   = new \DateTimeImmutable();
        $this->messageCoach = $message;
    }

    /**
     * Re-demander un accès refusé (remet en pending, efface la décision).
     */
    public function reDemander(): void
    {
        $this->statut       = self::STATUT_PENDING;
        $this->coach        = null;
        $this->decidedAt    = null;
        $this->messageCoach = null;
    }
}
