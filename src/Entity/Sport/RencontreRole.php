<?php

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\RencontreRoleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * RencontreRole — lien entre un User et un Rôle officiel sur une Rencontre.
 *
 * Une rencontre FFBB nécessite plusieurs officiels en plus des joueurs :
 *   - 1 à 2 arbitres (1er et 2e)
 *   - 1 responsable de salle (majeur licencié au club organisateur)
 *   - 1 marqueur (tient la feuille de marque)
 *   - 1 chronométreur
 *   - 1 opérateur e-marque (saisie officielle FFBB)
 *   - 1 statisticien MABB (saisie live pour stats internes — bloc V2)
 *
 * Si la FFBB a désigné un arbitre officiel pour le match
 * (Rencontre::arbitreExterneDesigne = true), les rôles ARBITRE_1/ARBITRE_2
 * ne sont pas inscriptibles côté club.
 *
 * Le passage en present=true par le staff après le match déclenche une
 * Mission de gamification (axe C bénévolat) — cf. RencontreController.
 *
 * Une seule personne par rôle par rencontre (UNIQUE constraint).
 */
#[ORM\Entity(repositoryClass: RencontreRoleRepository::class)]
#[ORM\Table(name: 'sport_rencontre_role')]
#[ORM\UniqueConstraint(name: 'uniq_rencontre_role', columns: ['rencontre_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class RencontreRole implements ClubAwareInterface
{
    // ===== CODES RÔLES =====
    public const ROLE_ARBITRE_1   = 'arbitre_1';
    public const ROLE_ARBITRE_2   = 'arbitre_2';
    public const ROLE_RESP_SALLE  = 'resp_salle';
    public const ROLE_MARQUEUR    = 'marqueur';
    public const ROLE_CHRONO      = 'chrono';
    public const ROLE_EMARQUE     = 'emarque';
    public const ROLE_STATS       = 'stats';

    public const ROLES = [
        self::ROLE_ARBITRE_1,
        self::ROLE_ARBITRE_2,
        self::ROLE_RESP_SALLE,
        self::ROLE_MARQUEUR,
        self::ROLE_CHRONO,
        self::ROLE_EMARQUE,
        self::ROLE_STATS,
    ];

    public const ROLE_LIBELLES = [
        self::ROLE_ARBITRE_1  => 'Arbitre 1',
        self::ROLE_ARBITRE_2  => 'Arbitre 2',
        self::ROLE_RESP_SALLE => 'Responsable de salle',
        self::ROLE_MARQUEUR   => 'Marqueur',
        self::ROLE_CHRONO     => 'Chronométreur',
        self::ROLE_EMARQUE    => 'Opérateur e-marque',
        self::ROLE_STATS      => 'Stats live MABB',
    ];

    public const ROLE_ICONES = [
        self::ROLE_ARBITRE_1  => 'bi-whistle',
        self::ROLE_ARBITRE_2  => 'bi-whistle',
        self::ROLE_RESP_SALLE => 'bi-shield-check',
        self::ROLE_MARQUEUR   => 'bi-pencil-square',
        self::ROLE_CHRONO     => 'bi-stopwatch',
        self::ROLE_EMARQUE    => 'bi-display',
        self::ROLE_STATS      => 'bi-graph-up',
    ];

    /**
     * Mapping rôle → type de Mission (gamification axe C).
     * Sert au RencontreController.validerPresenceRole pour créer la
     * bonne Mission quand un rôle est validé présent.
     */
    public const MAPPING_MISSION = [
        self::ROLE_ARBITRE_1  => 'arbitrage',         // Mission::TYPE_ARBITRAGE
        self::ROLE_ARBITRE_2  => 'arbitrage',
        self::ROLE_RESP_SALLE => 'tenue_table',       // assimilé officiel de match
        self::ROLE_MARQUEUR   => 'tenue_table',
        self::ROLE_CHRONO     => 'tenue_table',
        self::ROLE_EMARQUE    => 'tenue_table',
        self::ROLE_STATS      => 'autre',             // stats MABB internes, pas FFBB
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private ?string $role = null;

    /**
     * True quand le staff a validé que la personne a bien tenu son rôle
     * pendant le match. Déclenche la Mission de gamification.
     */
    #[ORM\Column]
    private bool $present = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): static { $this->rencontre = $r; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): static { $this->user = $u; return $this; }

    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $role): static { $this->role = $role; return $this; }

    public function getRoleLibelle(): string { return self::ROLE_LIBELLES[$this->role] ?? ($this->role ?? ''); }
    public function getRoleIcone(): string { return self::ROLE_ICONES[$this->role] ?? 'bi-person'; }

    public function isPresent(): bool { return $this->present; }
    public function setPresent(bool $v): static { $this->present = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getClub(): ?Club { return $this->rencontre?->getClub(); }
}
