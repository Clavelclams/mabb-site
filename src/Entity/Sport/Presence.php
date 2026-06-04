<?php

namespace App\Entity\Sport;

use App\Repository\Sport\PresenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Présence d'un Joueur à une Séance OU à une Rencontre.
 *
 * Choix de modélisation : une seule table avec deux FK nullables et mutuellement
 * exclusives (XOR). La validation isExactlyOneTargetSet() s'assure qu'on ne peut
 * pas créer un Presence rattaché aux deux ni à aucun.
 *
 * Alternative envisagée : deux tables séparées PresenceSeance / PresenceRencontre.
 * Rejetée car ça duplique la logique de présence (manuel/scan, motif d'absence)
 * et complique les requêtes "toutes les présences d'un joueur".
 */
#[ORM\Entity(repositoryClass: PresenceRepository::class)]
#[ORM\Table(name: 'presence')]
#[ORM\UniqueConstraint(name: 'unique_seance', columns: ['joueur_id', 'seance_id'])]
#[ORM\UniqueConstraint(name: 'unique_rencontre', columns: ['joueur_id', 'rencontre_id'])]
#[ORM\HasLifecycleCallbacks]
class Presence
{
    public const SOURCE_MANUEL = 'manuel';
    public const SOURCE_SCAN   = 'scan';
    public const SOURCES = [self::SOURCE_MANUEL, self::SOURCE_SCAN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class, inversedBy: 'presences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Seance::class, inversedBy: 'presences')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class, inversedBy: 'presences')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    #[ORM\Column]
    private bool $present = true;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::SOURCES)]
    private ?string $source = self::SOURCE_MANUEL;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $motifAbsence = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    /** Garde-fou : exactement UNE cible (séance OU rencontre) doit être définie. */
    #[Assert\Callback]
    public function isExactlyOneTargetSet(\Symfony\Component\Validator\Context\ExecutionContextInterface $ctx): void
    {
        $hasSeance = $this->seance !== null;
        $hasRencontre = $this->rencontre !== null;
        if ($hasSeance === $hasRencontre) {
            $ctx->buildViolation('Une présence doit cibler une Séance OU une Rencontre, pas les deux ni aucune.')
                ->atPath('seance')->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): static { $this->joueur = $j; return $this; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): static { $this->seance = $s; return $this; }
    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $r): static { $this->rencontre = $r; return $this; }
    public function isPresent(): bool { return $this->present; }
    public function setPresent(bool $p): static { $this->present = $p; return $this; }
    public function getSource(): ?string { return $this->source; }
    public function setSource(string $s): static { $this->source = $s; return $this; }
    public function getMotifAbsence(): ?string { return $this->motifAbsence; }
    public function setMotifAbsence(?string $m): static { $this->motifAbsence = $m; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
