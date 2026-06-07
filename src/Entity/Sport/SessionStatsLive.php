<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\SessionStatsLiveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Session de saisie Stats Live — V2.1d.
 *
 * BUT : permettre à PLUSIEURS bénévoles de saisir les stats du MÊME match
 * indépendamment. Chaque saisie = une session. Une seule peut devenir
 * OFFICIELLE (= alimente la fiche joueuse).
 *
 * CYCLE DE VIE :
 *
 *   EN_COURS ──► COMPLETE ──► OFFICIELLE
 *                    │             │
 *                    │             └──► (toute autre session OFFICIELLE de
 *                    │                   la même rencontre repasse en COMPLETE)
 *                    │
 *                    └──► ARCHIVEE (si abandon ou correction)
 *
 *   EN_COURS    : saisie en cours par le user (en train de cliquer)
 *   COMPLETE    : le user a fini sa saisie, prête à être évaluée
 *   OFFICIELLE  : promue par un coach/staff → c'est CETTE session qui compte
 *   ARCHIVEE    : ancienne version, gardée pour formation/comparaison
 *
 * USAGE FORMATION : 4 bénévoles saisissent le même match en formation.
 * Le coach compare leurs 4 sessions, en choisit une (la plus précise) qui
 * devient OFFICIELLE. Les 3 autres restent visibles en lecture seule pour
 * que les apprentis voient leurs erreurs.
 *
 * SOURCE DE VÉRITÉ : seuls les ActionMatch + PresenceTerrain de la session
 * OFFICIELLE alimentent les stats agrégées (fiche joueuse, EvaluationMatch).
 */
#[ORM\Entity(repositoryClass: SessionStatsLiveRepository::class)]
#[ORM\Table(name: 'session_stats_live')]
#[ORM\HasLifecycleCallbacks]
class SessionStatsLive implements ClubAwareInterface
{
    public const STATUT_EN_COURS    = 'EN_COURS';
    public const STATUT_COMPLETE    = 'COMPLETE';
    public const STATUT_OFFICIELLE  = 'OFFICIELLE';
    public const STATUT_ARCHIVEE    = 'ARCHIVEE';

    public const STATUTS = [
        self::STATUT_EN_COURS,
        self::STATUT_COMPLETE,
        self::STATUT_OFFICIELLE,
        self::STATUT_ARCHIVEE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_EN_COURS    => 'En cours',
        self::STATUT_COMPLETE    => 'Complète',
        self::STATUT_OFFICIELLE  => 'Officielle',
        self::STATUT_ARCHIVEE    => 'Archivée',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    /**
     * User qui saisit cette session. Conservé même si le compte est supprimé
     * (SET NULL pour RGPD) — on garde la trace pour la formation et l'audit.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Nom libre de la session (ex: "Sophie - première saisie",
     * "Formation 12 juin - équipe A"). Permet de distinguer plusieurs
     * sessions du même user.
     */
    #[ORM\Column(length: 100)]
    private string $nom = '';

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_COURS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Date où le user a marqué la session COMPLETE (a fini sa saisie). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Date où la session a été promue OFFICIELLE. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $promotedAt = null;

    /** User qui a promu officielle (staff/coach). NULL si jamais promue. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $promotedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    public function getClub(): ?Club
    {
        return $this->rencontre?->getClub();
    }

    public function isEnCours(): bool    { return $this->statut === self::STATUT_EN_COURS; }
    public function isComplete(): bool   { return $this->statut === self::STATUT_COMPLETE; }
    public function isOfficielle(): bool { return $this->statut === self::STATUT_OFFICIELLE; }
    public function isArchivee(): bool   { return $this->statut === self::STATUT_ARCHIVEE; }

    /**
     * Verrou édition : une session non EN_COURS est en LECTURE SEULE.
     * Empêche un bénévole de modifier sa saisie après l'avoir marquée complète.
     */
    public function isLectureSeule(): bool
    {
        return $this->statut !== self::STATUT_EN_COURS;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): self { $this->rencontre = $r; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $n): self { $this->nom = $n; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $s): self
    {
        if (!in_array($s, self::STATUTS, true)) {
            throw new \InvalidArgumentException(sprintf('Statut session invalide : "%s"', $s));
        }
        $this->statut = $s;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $d): self { $this->completedAt = $d; return $this; }

    public function getPromotedAt(): ?\DateTimeImmutable { return $this->promotedAt; }
    public function setPromotedAt(?\DateTimeImmutable $d): self { $this->promotedAt = $d; return $this; }

    public function getPromotedBy(): ?User { return $this->promotedBy; }
    public function setPromotedBy(?User $u): self { $this->promotedBy = $u; return $this; }
}
