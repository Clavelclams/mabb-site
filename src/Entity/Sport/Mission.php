<?php

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\MissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mission — action bénévole / vie de club d'un membre.
 *
 * Capture les contributions hors-terrain qui font vivre un club associatif :
 * tenue de table, arbitrage, buvette, encadrement, événements, etc.
 *
 * Cette donnée alimente l'axe C de la gamification (badges bénévolat) et
 * permet aussi de sortir des stats pour les dossiers de subvention
 * (combien de jeunes ont fait de l'engagement bénévole sur la saison ?).
 *
 * Multi-tenant strict : `club` obligatoire pour le ClubVoter.
 */
#[ORM\Entity(repositoryClass: MissionRepository::class)]
#[ORM\Table(name: 'sport_mission')]
#[ORM\Index(name: 'idx_mission_joueur_date', columns: ['joueur_id', 'date'])]
#[ORM\Index(name: 'idx_mission_club_date', columns: ['club_id', 'date'])]
#[ORM\HasLifecycleCallbacks]
class Mission implements ClubAwareInterface
{
    public const TYPE_TENUE_TABLE     = 'tenue_table';
    public const TYPE_ARBITRAGE       = 'arbitrage';
    public const TYPE_BUVETTE         = 'buvette';
    public const TYPE_ENCADREMENT     = 'encadrement';      // U17 qui aide les U11
    public const TYPE_EVENEMENT       = 'evenement';        // tournoi, soirée
    public const TYPE_AG              = 'ag';               // assemblée générale
    public const TYPE_FORMATION       = 'formation';        // formation arbitre, coach, e-marque, etc.
    public const TYPE_COMMUNICATION   = 'communication';    // post insta, parrainage
    public const TYPE_DON             = 'don';              // soutien financier, goodies
    public const TYPE_AUTRE           = 'autre';

    public const TYPES = [
        self::TYPE_TENUE_TABLE,
        self::TYPE_ARBITRAGE,
        self::TYPE_BUVETTE,
        self::TYPE_ENCADREMENT,
        self::TYPE_EVENEMENT,
        self::TYPE_AG,
        self::TYPE_FORMATION,
        self::TYPE_COMMUNICATION,
        self::TYPE_DON,
        self::TYPE_AUTRE,
    ];

    public const TYPE_LIBELLES = [
        self::TYPE_TENUE_TABLE   => 'Tenue de table',
        self::TYPE_ARBITRAGE     => 'Arbitrage',
        self::TYPE_BUVETTE       => 'Buvette / accueil',
        self::TYPE_ENCADREMENT   => 'Encadrement équipe jeune',
        self::TYPE_EVENEMENT     => 'Événement club',
        self::TYPE_AG            => 'Présence AG',
        self::TYPE_FORMATION     => 'Formation suivie',
        self::TYPE_COMMUNICATION => 'Communication / parrainage',
        self::TYPE_DON           => 'Don / soutien',
        self::TYPE_AUTRE         => 'Autre',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES, message: 'Type de mission invalide.')]
    private ?string $type = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'La date est obligatoire.')]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Staff qui a validé la mission (créateur de l'enregistrement).
     * Pour traçabilité — utile pour les audits subvention.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type; return $this; }

    public function getTypeLibelle(): string
    {
        return self::TYPE_LIBELLES[$this->type] ?? $this->type ?? '';
    }

    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    public function setDate(?\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getValidePar(): ?User { return $this->validePar; }
    public function setValidePar(?User $validePar): static { $this->validePar = $validePar; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
