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
    public function getTypeTir(): ?string { return $this->typeTir; }
    public function setTypeTir(?string $t): self { $this->typeTir = $t; return $this; }
    public function isEstReussi(): bool { return $this->estReussi; }
    public function setEstReussi(bool $r): self { $this->estReussi = $r; return $this; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $s): self { $this->source = $s; return $this; }
}
