<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\TirFfbbRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B22c 12/06/2026] Tir réussi extrait du PDF positiontir_*.pdf FFBB.
 *
 * V1 : juste le nom de la joueuse + type de tir. Position X/Y nullable.
 * V2 : extraction visuelle des "X" du PDF pour avoir position_x/y précis.
 *
 * Source ffbb vs stats_live :
 *   - ffbb       : tir extrait du PDF officiel (réussi par défaut, FFBB ne note pas les manqués)
 *   - stats_live : tir saisi en direct par l'assistant-coach (réussi OU manqué)
 *
 * Permet le toggle de shot chart entre 2 sources sur PIRB et Manager.
 */
#[ORM\Entity(repositoryClass: TirFfbbRepository::class)]
#[ORM\Table(name: 'tir_ffbb')]
class TirFfbb
{
    public const TYPE_2PT_INT = '2pt_int';
    public const TYPE_2PT_EXT = '2pt_ext';
    public const TYPE_3PT     = '3pt';

    public const SOURCE_FFBB        = 'ffbb';
    public const SOURCE_STATS_LIVE  = 'stats_live';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    #[ORM\Column(length: 120)]
    private string $nomJoueuse = '';

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $positionX = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $positionY = null;

    // ====================================================================
    // [V2.4 05/07/2026] COORDONNÉES FFBB BRUTES — précision maximale
    //
    // positionX/positionY (ci-dessus) sont le résultat d'une TRANSFORMATION
    // avec perte (mapping affine approximatif vers le terrain paysage +
    // arrondi 0-100) → points décalés par rapport aux zones dessinées.
    //
    // ffbbX / ffbbY stockent la position TELLE QUE PARSÉE dans le repère du
    // terrain du PDF e-Marque (portrait, panier en HAUT), en pour-mille :
    //   ffbbX : 0 = sideline gauche  … 1000 = sideline droite  (15 m)
    //   ffbbY : 0 = ligne de fond    … 1000 = ligne médiane    (14 m)
    // Affichées sur un terrain SVG aux proportions IDENTIQUES au doc FFBB
    // → zéro transformation à l'affichage = zéro décalage.
    // ====================================================================

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $ffbbX = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $ffbbY = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $typeTir = null;

    #[ORM\Column]
    private bool $estReussi = true;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_FFBB;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): self { $this->rencontre = $r; return $this; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getNomJoueuse(): string { return $this->nomJoueuse; }
    public function setNomJoueuse(string $n): self { $this->nomJoueuse = $n; return $this; }
    public function getPositionX(): ?int { return $this->positionX; }
    public function setPositionX(?int $x): self { $this->positionX = $x; return $this; }
    public function getPositionY(): ?int { return $this->positionY; }
    public function setPositionY(?int $y): self { $this->positionY = $y; return $this; }

    // [V2.4] Coordonnées brutes repère FFBB (pour-mille)
    public function getFfbbX(): ?int { return $this->ffbbX; }
    public function setFfbbX(?int $x): self { $this->ffbbX = $x; return $this; }
    public function getFfbbY(): ?int { return $this->ffbbY; }
    public function setFfbbY(?int $y): self { $this->ffbbY = $y; return $this; }
    public function getTypeTir(): ?string { return $this->typeTir; }
    public function setTypeTir(?string $t): self { $this->typeTir = $t; return $this; }
    public function isEstReussi(): bool { return $this->estReussi; }
    public function setEstReussi(bool $r): self { $this->estReussi = $r; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $s): self { $this->source = $s; return $this; }
}
