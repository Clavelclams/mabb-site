<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\NoteScolaireRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B33 12/06/2026] Note scolaire — 1 ligne par matière dans un bulletin.
 *
 * Permet de générer le "fromage de stats" (chart radar) et le suivi progression
 * par matière au fil des trimestres.
 */
#[ORM\Entity(repositoryClass: NoteScolaireRepository::class)]
#[ORM\Table(name: 'note_scolaire')]
class NoteScolaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BulletinScolaire::class, inversedBy: 'notes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BulletinScolaire $bulletin = null;

    #[ORM\Column(length: 80)]
    private string $matiere = '';

    #[ORM\Column(nullable: true)]
    private ?float $moyenne = null;

    #[ORM\Column(type: 'smallint')]
    private int $coefficient = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $appreciation = null;

    #[ORM\Column(nullable: true)]
    private ?float $moyenneClasse = null;

    #[ORM\Column(nullable: true)]
    private ?float $moyenneMaxClasse = null;

    #[ORM\Column(nullable: true)]
    private ?float $moyenneMinClasse = null;

    public function getId(): ?int { return $this->id; }
    public function getBulletin(): ?BulletinScolaire { return $this->bulletin; }
    public function setBulletin(?BulletinScolaire $b): self { $this->bulletin = $b; return $this; }
    public function getMatiere(): string { return $this->matiere; }
    public function setMatiere(string $m): self { $this->matiere = $m; return $this; }
    public function getMoyenne(): ?float { return $this->moyenne; }
    public function setMoyenne(?float $m): self { $this->moyenne = $m; return $this; }
    public function getCoefficient(): int { return $this->coefficient; }
    public function setCoefficient(int $c): self { $this->coefficient = $c; return $this; }
    public function getAppreciation(): ?string { return $this->appreciation; }
    public function setAppreciation(?string $a): self { $this->appreciation = $a; return $this; }
    public function getMoyenneClasse(): ?float { return $this->moyenneClasse; }
    public function setMoyenneClasse(?float $m): self { $this->moyenneClasse = $m; return $this; }
    public function getMoyenneMaxClasse(): ?float { return $this->moyenneMaxClasse; }
    public function setMoyenneMaxClasse(?float $m): self { $this->moyenneMaxClasse = $m; return $this; }
    public function getMoyenneMinClasse(): ?float { return $this->moyenneMinClasse; }
    public function setMoyenneMinClasse(?float $m): self { $this->moyenneMinClasse = $m; return $this; }
}
