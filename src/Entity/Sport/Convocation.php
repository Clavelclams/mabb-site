<?php

namespace App\Entity\Sport;

use App\Repository\Sport\ConvocationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Convocation à une Rencontre.
 *
 * RSVP : avant le match, le coach convoque, la joueuse répond
 * (présente / absente / incertaine). Plus riche qu'un simple "Presence"
 * parce que c'est PRÉ-match (anticipation), pas POST.
 */
#[ORM\Entity(repositoryClass: ConvocationRepository::class)]
#[ORM\Table(name: 'convocation')]
#[ORM\UniqueConstraint(name: 'unique_convocation', columns: ['rencontre_id', 'joueur_id'])]
#[ORM\HasLifecycleCallbacks]
class Convocation
{
    public const REPONSE_PRESENT   = 'present';
    public const REPONSE_ABSENT    = 'absent';
    public const REPONSE_INCERTAIN = 'incertain';
    public const REPONSES = [self::REPONSE_PRESENT, self::REPONSE_ABSENT, self::REPONSE_INCERTAIN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class, inversedBy: 'convocations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class, inversedBy: 'convocations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Réponse de la joueuse — null = pas encore répondu */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: self::REPONSES)]
    private ?string $reponse = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motif = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $repondueAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): static { $this->rencontre = $r; return $this; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): static { $this->joueur = $j; return $this; }
    public function getReponse(): ?string { return $this->reponse; }
    public function setReponse(?string $r): static
    {
        $this->reponse = $r;
        if ($r !== null) { $this->repondueAt = new \DateTimeImmutable(); }
        return $this;
    }
    public function getMotif(): ?string { return $this->motif; }
    public function setMotif(?string $m): static { $this->motif = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getRepondueAt(): ?\DateTimeImmutable { return $this->repondueAt; }
}
