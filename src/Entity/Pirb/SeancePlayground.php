<?php

declare(strict_types=1);

namespace App\Entity\Pirb;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Sport\Joueur;
use App\Repository\Pirb\SeancePlaygroundRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * SeancePlayground — [Engagement V1, 10/07/2026] une séance de jeu
 * Playground (tir auto / dribble auto) remontée par l'app.
 *
 * POURQUOI CETTE ENTITÉ : tant que les séances restaient dans AsyncStorage
 * (le téléphone), aucun CLASSEMENT n'était possible — or c'est le classement
 * et les paliers qui donnent envie de REVENIR. Le serveur devient la mémoire
 * partagée : l'app garde sa copie locale (offline d'abord), et pousse chaque
 * séance ici en tâche de fond.
 *
 * CONFIANCE : les chiffres viennent du client (le jeu tourne en local).
 * V1 : on borne les valeurs au contrôleur et on assume — l'enjeu est un
 * classement de vestiaire entre coéquipières, pas un concours national.
 * Si un jour ça triche, la parade est côté produit (validation coach).
 */
#[ORM\Entity(repositoryClass: SeancePlaygroundRepository::class)]
#[ORM\Table(name: 'pirb_seance_playground')]
#[ORM\Index(name: 'idx_psp_joueur_date', columns: ['joueur_id', 'created_at'])]
#[ORM\Index(name: 'idx_psp_mode_date', columns: ['mode', 'created_at'])]
class SeancePlayground implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** 'tir' ou 'dribble' (contrat types/pirb.ts::ModePractice). */
    #[ORM\Column(length: 10)]
    private ?string $mode = null;

    #[ORM\Column]
    private int $reussis = 0;

    #[ORM\Column]
    private int $rates = 0;

    /** Score du jeu (tir : réussis×10 ; dribble : score à niveaux, plus riche). */
    #[ORM\Column]
    private int $score = 0;

    #[ORM\Column]
    private int $dureeSecondes = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Factory : une séance prête à persister (même convention que Follow). */
    public static function creer(Joueur $joueur, string $mode, int $reussis, int $rates, int $score, int $dureeSecondes): self
    {
        $s = new self();
        $s->joueur = $joueur;
        $s->mode = $mode;
        $s->reussis = $reussis;
        $s->rates = $rates;
        $s->score = $score;
        $s->dureeSecondes = $dureeSecondes;
        return $s;
    }

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function getMode(): ?string { return $this->mode; }
    public function getReussis(): int { return $this->reussis; }
    public function getRates(): int { return $this->rates; }
    public function getScore(): int { return $this->score; }
    public function getDureeSecondes(): int { return $this->dureeSecondes; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }
}
