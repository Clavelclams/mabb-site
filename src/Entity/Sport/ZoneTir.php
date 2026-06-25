<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\ZoneTirRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ZoneTir — un spot sur le terrain avec des stats agrégées.
 *
 * Stocke le résultat d'une zone de tir (ex : "9/16 à 3 points côté gauche").
 * positionX et positionY sont normalisées [0.0–1.0] par rapport au demi-terrain :
 *   X=0 = bord gauche, X=1 = bord droit
 *   Y=0 = fond du terrain (panier), Y=1 = milieu terrain (ligne médiane)
 *
 * Pourquoi des coordonnées normalisées (et pas des pixels) :
 *   → résistant au responsive (le canvas peut être 300px ou 600px selon l'écran)
 *   → calcul côté JS : pixelX = posX * canvasWidth
 *
 * Types de tir :
 *   2pt_int = dans la raquette / lay-up
 *   2pt_ext = mi-distance (2pts hors raquette)
 *   3pt     = derrière l'arc
 *   lancer  = lancer franc (affiché à part sur la carte)
 *
 * CONTRAINTE BDD : reussis <= tentatives (CHECK SQL, répliquée en validator Symfony)
 */
#[ORM\Entity(repositoryClass: ZoneTirRepository::class)]
#[ORM\Table(name: 'zone_tir')]
class ZoneTir
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SeanceTir::class, inversedBy: 'zones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SeanceTir $seanceTir = null;

    /**
     * Position X normalisée [0.0–1.0].
     * 0 = bord gauche, 1 = bord droit.
     */
    #[ORM\Column(type: 'float')]
    #[Assert\Range(min: 0.0, max: 1.0)]
    private float $positionX = 0.5;

    /**
     * Position Y normalisée [0.0–1.0].
     * 0 = fond du terrain (panier), 1 = milieu terrain.
     */
    #[ORM\Column(type: 'float')]
    #[Assert\Range(min: 0.0, max: 1.0)]
    private float $positionY = 0.5;

    /**
     * Type du tir : 2pt_int | 2pt_ext | 3pt | lancer.
     * Défaut : auto-détecté en JS selon la position (zone arc ou lancer).
     */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: SeanceTir::TYPES_TIR)]
    private string $typeTir = SeanceTir::TYPE_2PT_EXT;

    /** Nombre de tentatives pour cette zone — min 1. */
    #[ORM\Column(type: 'smallint')]
    #[Assert\GreaterThanOrEqual(1)]
    private int $tentatives = 1;

    /** Nombre de tirs réussis — max = tentatives. */
    #[ORM\Column(type: 'smallint')]
    #[Assert\GreaterThanOrEqual(0)]
    private int $reussis = 0;

    // ====== Getters / Setters ======

    public function getId(): ?int { return $this->id; }
    public function getSeanceTir(): ?SeanceTir { return $this->seanceTir; }
    public function setSeanceTir(?SeanceTir $s): static { $this->seanceTir = $s; return $this; }
    public function getPositionX(): float { return $this->positionX; }
    public function setPositionX(float $x): static { $this->positionX = max(0.0, min(1.0, $x)); return $this; }
    public function getPositionY(): float { return $this->positionY; }
    public function setPositionY(float $y): static { $this->positionY = max(0.0, min(1.0, $y)); return $this; }
    public function getTypeTir(): string { return $this->typeTir; }
    public function setTypeTir(string $t): static { $this->typeTir = $t; return $this; }
    public function getTentatives(): int { return $this->tentatives; }

    public function setTentatives(int $t): static
    {
        if ($t < 1) $t = 1;
        $this->tentatives = $t;
        // Garder la cohérence : réussis ne peut pas dépasser les tentatives
        if ($this->reussis > $t) $this->reussis = $t;
        return $this;
    }

    public function getReussis(): int { return $this->reussis; }

    public function setReussis(int $r): static
    {
        $this->reussis = max(0, min($r, $this->tentatives));
        return $this;
    }

    /**
     * Pourcentage de réussite pour cette zone.
     * Retourne null si aucune tentative (impossible normalement).
     */
    public function getPourcentage(): ?float
    {
        if ($this->tentatives === 0) return null;
        return round($this->reussis / $this->tentatives * 100, 1);
    }

    /**
     * Label lisible ex : "9/16" (utile dans les templates).
     */
    public function getRatio(): string
    {
        return $this->reussis . '/' . $this->tentatives;
    }

    /**
     * Couleur HSL pour la shot map selon le pourcentage.
     * Rouge (0%) → jaune (50%) → vert (100%).
     *
     * Utilisé par le template pour colorier le dot sur la carte.
     * Exemple : color="hsl(120, 80%, 45%)" pour 100%
     *
     * @return string ex: "hsl(120, 80%, 45%)"
     */
    public function getCouleurHsl(): string
    {
        $pct = $this->getPourcentage() ?? 0;
        // Hue : 0 (rouge) à 120 (vert) selon le taux
        $hue = (int) round($pct * 1.2);
        return sprintf('hsl(%d, 80%%, 45%%)', $hue);
    }

    /**
     * Est-ce un tir à 3 points ?
     */
    public function is3Points(): bool { return $this->typeTir === SeanceTir::TYPE_3PT; }

    /**
     * Est-ce un lancer franc ?
     */
    public function isLancerFranc(): bool { return $this->typeTir === SeanceTir::TYPE_LANCER; }
}
