<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\PresenceTerrainRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une "période sur le terrain" d'un joueur dans un match — Stats Live V2.1b.
 *
 * MODÈLE :
 *   - Une joueuse peut avoir PLUSIEURS PresenceTerrain pour le même match
 *     (sortie puis ré-entrée comptent comme 2 lignes distinctes).
 *   - secondesEntree : temps ABSOLU écoulé depuis Q1 0:00 (en secondes).
 *     Exemple Q2 à 5:00 avec format 4×10 min → 10×60 + 5×60 = 900s.
 *   - secondesSortie : NULL tant que la joueuse est encore sur le terrain.
 *     Quand elle sort, on fixe la valeur.
 *
 * TEMPS DE JEU TOTAL d'une joueuse pour un match =
 *   SUM(secondesSortie - secondesEntree) pour toutes ses lignes.
 *   Les lignes avec secondesSortie = NULL sont "encore en cours" → on
 *   utilise le chrono actuel pour calculer.
 *
 * Pourquoi pas un seul champ "estSurTerrain" boolean sur Joueur ?
 *   - Permet l'historique complet (qui était sur le terrain quand ?)
 *   - Permet de corriger une erreur de saisie a posteriori
 *   - Permet de calculer le temps de jeu précis
 *
 * MULTI-TENANT : délégation via la Rencontre → son Club.
 */
#[ORM\Entity(repositoryClass: PresenceTerrainRepository::class)]
#[ORM\Table(name: 'presence_terrain')]
#[ORM\HasLifecycleCallbacks]
class PresenceTerrain implements ClubAwareInterface
{
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

    /** Session de saisie (V2.1d) — nullable pour compat avec données antérieures. */
    #[ORM\ManyToOne(targetEntity: SessionStatsLive::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SessionStatsLive $session = null;

    /** Temps ABSOLU d'entrée sur le terrain (secondes écoulées depuis Q1 0:00). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $secondesEntree = 0;

    /**
     * Temps ABSOLU de sortie. NULL = encore sur le terrain.
     * Mis à jour soit par un changement, soit en fin de match (auto-close).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $secondesSortie = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

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

    /**
     * True si la joueuse est encore sur le terrain (pas de secondesSortie).
     */
    public function estEnCours(): bool
    {
        return $this->secondesSortie === null;
    }

    /**
     * Durée de cette présence en secondes.
     * Si encore en cours, retourne null (caller doit utiliser le chrono actuel).
     */
    public function getDureeSecondes(): ?int
    {
        if ($this->secondesSortie === null) {
            return null;
        }
        return max(0, $this->secondesSortie - $this->secondesEntree);
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): self { $this->rencontre = $r; return $this; }

    public function getSession(): ?SessionStatsLive { return $this->session; }
    public function setSession(?SessionStatsLive $s): self { $this->session = $s; return $this; }

    public function getSecondesEntree(): int { return $this->secondesEntree; }
    public function setSecondesEntree(int $s): self
    {
        if ($s < 0) {
            throw new \InvalidArgumentException('secondesEntree doit être >= 0');
        }
        $this->secondesEntree = $s;
        return $this;
    }

    public function getSecondesSortie(): ?int { return $this->secondesSortie; }
    public function setSecondesSortie(?int $s): self
    {
        if ($s !== null && $s < $this->secondesEntree) {
            throw new \InvalidArgumentException('secondesSortie doit être >= secondesEntree');
        }
        $this->secondesSortie = $s;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
