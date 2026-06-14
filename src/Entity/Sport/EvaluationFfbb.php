<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\EvaluationFfbbRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B22b 12/06/2026] Stats individuelles FFBB extraites du PDF resume_*.pdf.
 *
 * Source : pipeline FfbbResumeParser. 1 ligne par joueuse par match.
 *
 * DIFFÉRENCE avec EvaluationMatch :
 *   - EvaluationMatch = saisie manuelle du coach après match (sa vision)
 *   - EvaluationFfbb  = stats officielles extraites du PDF FFBB (vision officielle)
 *
 * Permet un TOGGLE "Stats coach" / "Stats FFBB" sur la page match côté PIRB
 * et côté Manager pour comparer les 2 visions.
 *
 * joueur_id nullable : si on n'arrive pas à matcher le numéro de maillot
 * à une joueuse du club, on garde nom_complet en clair et joueur_id=null.
 */
#[ORM\Entity(repositoryClass: EvaluationFfbbRepository::class)]
#[ORM\Table(name: 'evaluation_ffbb')]
#[ORM\UniqueConstraint(name: 'UNQ_EFFBB_RENC_NUM', columns: ['rencontre_id', 'numero_maillot'])]
class EvaluationFfbb
{
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

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $numeroMaillot = null;

    #[ORM\Column(length: 120)]
    private string $nomComplet = '';

    #[ORM\Column]
    private bool $estStarter = false;

    #[ORM\Column]
    private int $minutesJouees = 0;

    #[ORM\Column]
    private int $points = 0;

    // [FIX 14/06/2026] Force le mapping snake_case lisible (tirs_2pt_*) car la
    // convention par défaut Doctrine génère "tirs2pt_*" (chiffre collé au mot
    // précédent), incompatible avec la migration Version20260612120000 qui a
    // créé les colonnes "tirs_2pt_*". Même problème déjà rencontré sur code_emarque.
    #[ORM\Column(name: 'tirs_2pt_reussis')]
    private int $tirs2ptReussis = 0;
    #[ORM\Column(name: 'tirs_2pt_tentes')]
    private int $tirs2ptTentes = 0;
    #[ORM\Column(name: 'tirs_3pt_reussis')]
    private int $tirs3ptReussis = 0;
    #[ORM\Column(name: 'tirs_3pt_tentes')]
    private int $tirs3ptTentes = 0;
    #[ORM\Column]
    private int $lancersReussis = 0;
    #[ORM\Column]
    private int $lancersTentes = 0;

    #[ORM\Column]
    private int $rebondsOff = 0;
    #[ORM\Column]
    private int $rebondsDef = 0;
    #[ORM\Column]
    private int $passesD = 0;
    #[ORM\Column]
    private int $interceptions = 0;
    #[ORM\Column]
    private int $contres = 0;
    #[ORM\Column]
    private int $fautesCommises = 0;
    #[ORM\Column]
    private int $pertesBalle = 0;
    #[ORM\Column]
    private int $evalFfbb = 0;

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
    public function getNumeroMaillot(): ?int { return $this->numeroMaillot; }
    public function setNumeroMaillot(?int $n): self { $this->numeroMaillot = $n; return $this; }
    public function getNomComplet(): string { return $this->nomComplet; }
    public function setNomComplet(string $n): self { $this->nomComplet = $n; return $this; }
    public function isEstStarter(): bool { return $this->estStarter; }
    public function setEstStarter(bool $s): self { $this->estStarter = $s; return $this; }
    public function getMinutesJouees(): int { return $this->minutesJouees; }
    public function setMinutesJouees(int $m): self { $this->minutesJouees = $m; return $this; }
    public function getPoints(): int { return $this->points; }
    public function setPoints(int $p): self { $this->points = $p; return $this; }
    public function getTirs2ptReussis(): int { return $this->tirs2ptReussis; }
    public function setTirs2ptReussis(int $v): self { $this->tirs2ptReussis = $v; return $this; }
    public function getTirs2ptTentes(): int { return $this->tirs2ptTentes; }
    public function setTirs2ptTentes(int $v): self { $this->tirs2ptTentes = $v; return $this; }
    public function getTirs3ptReussis(): int { return $this->tirs3ptReussis; }
    public function setTirs3ptReussis(int $v): self { $this->tirs3ptReussis = $v; return $this; }
    public function getTirs3ptTentes(): int { return $this->tirs3ptTentes; }
    public function setTirs3ptTentes(int $v): self { $this->tirs3ptTentes = $v; return $this; }
    public function getLancersReussis(): int { return $this->lancersReussis; }
    public function setLancersReussis(int $v): self { $this->lancersReussis = $v; return $this; }
    public function getLancersTentes(): int { return $this->lancersTentes; }
    public function setLancersTentes(int $v): self { $this->lancersTentes = $v; return $this; }
    public function getRebondsOff(): int { return $this->rebondsOff; }
    public function setRebondsOff(int $v): self { $this->rebondsOff = $v; return $this; }
    public function getRebondsDef(): int { return $this->rebondsDef; }
    public function setRebondsDef(int $v): self { $this->rebondsDef = $v; return $this; }
    public function getPassesD(): int { return $this->passesD; }
    public function setPassesD(int $v): self { $this->passesD = $v; return $this; }
    public function getInterceptions(): int { return $this->interceptions; }
    public function setInterceptions(int $v): self { $this->interceptions = $v; return $this; }
    public function getContres(): int { return $this->contres; }
    public function setContres(int $v): self { $this->contres = $v; return $this; }
    public function getFautesCommises(): int { return $this->fautesCommises; }
    public function setFautesCommises(int $v): self { $this->fautesCommises = $v; return $this; }
    public function getPertesBalle(): int { return $this->pertesBalle; }
    public function setPertesBalle(int $v): self { $this->pertesBalle = $v; return $this; }
    public function getEvalFfbb(): int { return $this->evalFfbb; }
    public function setEvalFfbb(int $v): self { $this->evalFfbb = $v; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
