<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\AffectationMatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Affectation d'un membre du staff/bénévole à un rôle pour une Rencontre.
 *
 * Workflow :
 *   - Admin assigne directement   → statut ASSIGNE
 *   - Bénévole candidate          → statut CANDIDAT  (admin doit valider)
 *   - Admin confirme candidature  → statut CONFIRME
 *   - Service civique absent      → statut ABSENT
 *
 * Un seul user par rôle par rencontre (unique constraint).
 * Plusieurs candidatures possibles en attente (CANDIDAT) sur le même rôle.
 */
#[ORM\Entity(repositoryClass: AffectationMatchRepository::class)]
#[ORM\Table(name: 'affectation_match')]
class AffectationMatch
{
    // ── Rôles disponibles ──────────────────────────────────────────────────
    public const ROLE_DELEGUE           = 'DELEGUE';
    public const ROLE_CHRONO            = 'CHRONO';
    public const ROLE_EMARQUE           = 'EMARQUE';
    public const ROLE_ARBITRE_1         = 'ARBITRE_1';
    public const ROLE_ARBITRE_2         = 'ARBITRE_2';
    public const ROLE_BUVETTE           = 'BUVETTE';
    public const ROLE_OPERATEUR         = 'OPERATEUR';
    public const ROLE_STATS_LIVE        = 'STATS_LIVE';
    public const ROLE_RESPONSABLE_SALLE = 'RESPONSABLE_SALLE';

    public const ROLES = [
        self::ROLE_DELEGUE           => 'Délégué Club',
        self::ROLE_CHRONO            => 'Chronométreur',
        self::ROLE_EMARQUE           => 'E-Marque',
        self::ROLE_ARBITRE_1         => 'Arbitre 1',
        self::ROLE_ARBITRE_2         => 'Arbitre 2',
        self::ROLE_BUVETTE           => 'Buvette',
        self::ROLE_OPERATEUR         => 'Opérateur e-marque',
        self::ROLE_STATS_LIVE        => 'Stats Live MABB',
        self::ROLE_RESPONSABLE_SALLE => 'Responsable de salle',
    ];

    // ── Statuts ────────────────────────────────────────────────────────────
    public const STATUT_ASSIGNE  = 'ASSIGNE';   // Admin a assigné directement
    public const STATUT_CANDIDAT = 'CANDIDAT';  // Bénévole candidaté, en attente
    public const STATUT_CONFIRME = 'CONFIRME';  // Admin a confirmé la candidature
    public const STATUT_ABSENT   = 'ABSENT';    // Marqué absent

    public const STATUTS = [
        self::STATUT_ASSIGNE,
        self::STATUT_CANDIDAT,
        self::STATUT_CONFIRME,
        self::STATUT_ABSENT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** @see self::ROLES */
    #[ORM\Column(length: 30)]
    private string $role = self::ROLE_DELEGUE;

    /** @see self::STATUTS */
    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_ASSIGNE;

    /** Note interne (ex: "Absent — examen") */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ──────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): self { $this->rencontre = $r; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $r): self { $this->role = $r; return $this; }

    public function getRoleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): self { $this->statut = $s; $this->updatedAt = new \DateTimeImmutable(); return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $n): self { $this->note = $n; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function isAssigne(): bool  { return $this->statut === self::STATUT_ASSIGNE; }
    public function isCandidature(): bool { return $this->statut === self::STATUT_CANDIDAT; }
    public function isConfirme(): bool { return $this->statut === self::STATUT_CONFIRME; }
    public function isAbsent(): bool   { return $this->statut === self::STATUT_ABSENT; }

    /**
     * L'affectation "occupe" un rôle (même si la personne est absente).
     * → sert à l'affichage dans le tableau de rôles.
     */
    public function isActif(): bool
    {
        return in_array($this->statut, [self::STATUT_ASSIGNE, self::STATUT_CONFIRME, self::STATUT_ABSENT], true);
    }

    /**
     * Le rôle est couvert par quelqu'un de disponible (pas absent).
     * → sert à calculer si le rôle est "réellement pourvu".
     */
    public function isCouvert(): bool
    {
        return in_array($this->statut, [self::STATUT_ASSIGNE, self::STATUT_CONFIRME], true);
    }
}
