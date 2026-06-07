<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\ActionMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ActionMatch — événement atomique pendant un match (saisie LIVE).
 *
 * UNE LIGNE = UNE ACTION (un tir, un rebond, une faute, etc.).
 * Granularité fine pour reconstruire toute l'histoire du match :
 *   - Shot chart (position de chaque tir)
 *   - Momentum (sequence des actions)
 *   - Stats agrégées via le service ActionMatchAggregator
 *
 * COHABITATION AVEC EvaluationMatch :
 *   - ActionMatch  = source de vérité LIVE (table de marque MABB)
 *   - EvaluationMatch = source de vérité MANUELLE (import FFBB / saisie après match)
 *   Le service ActionMatchAggregator peut générer une EvaluationMatch à partir
 *   des ActionMatch d'un joueur sur un match (pour export ou archivage).
 *
 * MULTI-TENANT :
 *   ClubAwareInterface via $this->joueur->getClub() (même pattern que
 *   EvaluationMatch et Presence).
 *
 * INDEX :
 *   (rencontre_id, joueur_id) couvre les queries d'agrégation par joueur/match.
 *   (rencontre_id, type) couvre les comptages globaux (ex: tous les tirs 3pts).
 */
#[ORM\Entity(repositoryClass: ActionMatchRepository::class)]
#[ORM\Table(name: 'action_match')]
#[ORM\Index(name: 'idx_am_rencontre_joueur', columns: ['rencontre_id', 'joueur_id'])]
#[ORM\Index(name: 'idx_am_rencontre_type', columns: ['rencontre_id', 'type'])]
#[ORM\HasLifecycleCallbacks]
class ActionMatch implements ClubAwareInterface
{
    // ====================================================================
    // TYPES D'ACTIONS — constantes utilisées dans le code et stockées en string
    // String (et pas enum PHP natif) pour rétrocompat avec PHP 8.0 et fixtures.
    // ====================================================================

    // --- Tirs ---
    public const TYPE_TIR_2PT_INT_REUSSI  = 'tir_2pt_int_reussi';   // intérieur (raquette/lay-up)
    public const TYPE_TIR_2PT_INT_RATE    = 'tir_2pt_int_rate';
    public const TYPE_TIR_2PT_EXT_REUSSI  = 'tir_2pt_ext_reussi';   // extérieur (mi-distance)
    public const TYPE_TIR_2PT_EXT_RATE    = 'tir_2pt_ext_rate';
    public const TYPE_TIR_3PT_REUSSI      = 'tir_3pt_reussi';
    public const TYPE_TIR_3PT_RATE        = 'tir_3pt_rate';
    public const TYPE_LANCER_REUSSI       = 'lancer_reussi';
    public const TYPE_LANCER_RATE         = 'lancer_rate';

    // --- Rebonds ---
    public const TYPE_REBOND_OFFENSIF     = 'rebond_offensif';
    public const TYPE_REBOND_DEFENSIF     = 'rebond_defensif';

    // --- Défense / passes ---
    public const TYPE_PASSE_DECISIVE      = 'passe_decisive';
    public const TYPE_INTERCEPTION        = 'interception';
    public const TYPE_CONTRE              = 'contre';
    public const TYPE_CONTRE_SUBI         = 'contre_subi';

    // --- Erreurs ---
    public const TYPE_PERTE_BALLE         = 'perte_balle';
    public const TYPE_FAUTE_COMMISE       = 'faute_commise';
    public const TYPE_FAUTE_PROVOQUEE     = 'faute_provoquee';

    // --- Substitutions / temps morts ---
    public const TYPE_ENTREE              = 'entree';
    public const TYPE_SORTIE              = 'sortie';
    public const TYPE_TIME_OUT_DEMANDE    = 'time_out';

    /** Tous les types valides (whitelist pour validation) */
    public const TYPES = [
        self::TYPE_TIR_2PT_INT_REUSSI, self::TYPE_TIR_2PT_INT_RATE,
        self::TYPE_TIR_2PT_EXT_REUSSI, self::TYPE_TIR_2PT_EXT_RATE,
        self::TYPE_TIR_3PT_REUSSI, self::TYPE_TIR_3PT_RATE,
        self::TYPE_LANCER_REUSSI, self::TYPE_LANCER_RATE,
        self::TYPE_REBOND_OFFENSIF, self::TYPE_REBOND_DEFENSIF,
        self::TYPE_PASSE_DECISIVE, self::TYPE_INTERCEPTION,
        self::TYPE_CONTRE, self::TYPE_CONTRE_SUBI,
        self::TYPE_PERTE_BALLE, self::TYPE_FAUTE_COMMISE, self::TYPE_FAUTE_PROVOQUEE,
        self::TYPE_ENTREE, self::TYPE_SORTIE, self::TYPE_TIME_OUT_DEMANDE,
    ];

    /** Types qui ont besoin d'une position X/Y (tirs uniquement) */
    public const TYPES_AVEC_POSITION = [
        self::TYPE_TIR_2PT_INT_REUSSI, self::TYPE_TIR_2PT_INT_RATE,
        self::TYPE_TIR_2PT_EXT_REUSSI, self::TYPE_TIR_2PT_EXT_RATE,
        self::TYPE_TIR_3PT_REUSSI, self::TYPE_TIR_3PT_RATE,
    ];

    /** Types qui rapportent des points */
    public const TYPES_QUI_MARQUENT = [
        self::TYPE_TIR_2PT_INT_REUSSI => 2,
        self::TYPE_TIR_2PT_EXT_REUSSI => 2,
        self::TYPE_TIR_3PT_REUSSI     => 3,
        self::TYPE_LANCER_REUSSI      => 1,
    ];

    // ====================================================================
    // QUART-TEMPS — incluant prolongations
    // ====================================================================

    public const QT_1 = 'QT1';
    public const QT_2 = 'QT2';
    public const QT_3 = 'QT3';
    public const QT_4 = 'QT4';
    public const EXT_1 = 'EXT1';
    public const EXT_2 = 'EXT2';
    public const QUARTS_TEMPS = [self::QT_1, self::QT_2, self::QT_3, self::QT_4, self::EXT_1, self::EXT_2];

    // ====================================================================
    // CHAMPS
    // ====================================================================

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
     * Session de saisie (V2.1d). Nullable pour permettre la coexistence avec
     * les ActionMatch créées AVANT V2.1d (rattachées directement à la rencontre).
     * À terme, toute nouvelle action sera rattachée à une session.
     */
    #[ORM\ManyToOne(targetEntity: SessionStatsLive::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SessionStatsLive $session = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::TYPES, message: 'Type d\'action invalide.')]
    private ?string $type = null;

    #[ORM\Column(length: 5)]
    #[Assert\Choice(choices: self::QUARTS_TEMPS)]
    private ?string $quartTemps = self::QT_1;

    /**
     * Minute du quart-temps où l'action a eu lieu (0-12 pour QT régulier, 0-5 pour prolongation).
     * Sert au tri chronologique et à reconstruire le momentum du match.
     */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 0, max: 15)]
    private int $minute = 0;

    /**
     * Secondes dans la minute (0-59). Précision fine pour la reconstitution du match.
     */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 0, max: 59)]
    private int $secondes = 0;

    /**
     * Position X normalisée 0-1 (uniquement pour les tirs). Null pour les autres actions.
     * Convention : 0 = gauche du demi-terrain de tir, 1 = droite. Toujours du côté
     * du panier attaqué (on bascule mentalement si nécessaire à la mi-temps).
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: 0, max: 1)]
    private ?float $positionX = null;

    /**
     * Position Y normalisée 0-1. 0 = ligne de fond (sous le panier), 1 = milieu de terrain.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    #[Assert\Range(min: 0, max: 1)]
    private ?float $positionY = null;

    /**
     * Joueuse qui a fait la passe décisive AVANT ce tir (lien optionnel).
     * Utilisé pour : sur un TIR_REUSSI, on peut lier la joueuse qui a fait l'assist.
     * Le service d'agrégation crée alors automatiquement une PASSE_DECISIVE pour cette joueuse.
     */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $assistJoueur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // MULTI-TENANT
    // ====================================================================

    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }

    // ====================================================================
    // MÉTHODES MÉTIER
    // ====================================================================

    /**
     * Points marqués par cette action (0 pour tout sauf les tirs/LF réussis).
     */
    public function getPointsMarques(): int
    {
        return self::TYPES_QUI_MARQUENT[$this->type] ?? 0;
    }

    /**
     * Catégorie large de l'action — utile pour grouper en UI (couleurs, icônes).
     */
    public function getCategorie(): string
    {
        return match (true) {
            str_starts_with($this->type ?? '', 'tir_')      => 'tir',
            str_starts_with($this->type ?? '', 'lancer_')   => 'lancer',
            str_starts_with($this->type ?? '', 'rebond_')   => 'rebond',
            in_array($this->type, [self::TYPE_PASSE_DECISIVE, self::TYPE_INTERCEPTION, self::TYPE_CONTRE], true) => 'defense',
            in_array($this->type, [self::TYPE_PERTE_BALLE, self::TYPE_FAUTE_COMMISE, self::TYPE_CONTRE_SUBI], true) => 'erreur',
            in_array($this->type, [self::TYPE_FAUTE_PROVOQUEE], true) => 'gagnant',
            in_array($this->type, [self::TYPE_ENTREE, self::TYPE_SORTIE, self::TYPE_TIME_OUT_DEMANDE], true) => 'meta',
            default => 'autre',
        };
    }

    /**
     * Est-ce une action de tir (a une position X/Y) ?
     */
    public function estUnTir(): bool
    {
        return in_array($this->type, self::TYPES_AVEC_POSITION, true);
    }

    /**
     * Est-ce un tir RÉUSSI ?
     */
    public function estReussi(): bool
    {
        return str_ends_with($this->type ?? '', '_reussi');
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): static { $this->joueur = $j; return $this; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): static { $this->rencontre = $r; return $this; }

    public function getSession(): ?SessionStatsLive { return $this->session; }
    public function setSession(?SessionStatsLive $s): static { $this->session = $s; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(string $type): static
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Type d'action invalide : $type");
        }
        $this->type = $type;
        return $this;
    }

    public function getQuartTemps(): ?string { return $this->quartTemps; }
    public function setQuartTemps(string $qt): static
    {
        if (!in_array($qt, self::QUARTS_TEMPS, true)) {
            throw new \InvalidArgumentException("Quart-temps invalide : $qt");
        }
        $this->quartTemps = $qt;
        return $this;
    }

    public function getMinute(): int { return $this->minute; }
    public function setMinute(int $m): static { $this->minute = $m; return $this; }

    public function getSecondes(): int { return $this->secondes; }
    public function setSecondes(int $s): static { $this->secondes = $s; return $this; }

    public function getPositionX(): ?float { return $this->positionX; }
    public function setPositionX(?float $x): static { $this->positionX = $x; return $this; }

    public function getPositionY(): ?float { return $this->positionY; }
    public function setPositionY(?float $y): static { $this->positionY = $y; return $this; }

    public function getAssistJoueur(): ?Joueur { return $this->assistJoueur; }
    public function setAssistJoueur(?Joueur $j): static { $this->assistJoueur = $j; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
