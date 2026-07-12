<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\OtmInterdictionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * OtmInterdiction — [OTM V2, 12/07/2026]
 *
 * « Cette personne peut tenir n'importe quel poste SAUF celui-ci. »
 *
 * Exemple réel : un membre du staff très mauvais en arbitrage. On lui interdit
 * ARBITRE_1 et ARBITRE_2 : il ne pourra ni s'y inscrire lui-même, ni y être
 * placé d'office par l'auto-affectation du mercredi, et le dirigeant ne pourra
 * pas l'y glisser par erreur dans le kanban.
 *
 * Une ligne = un poste interdit pour une personne dans un club.
 * Le poste est un code de AffectationMatch::ROLES.
 */
#[ORM\Entity(repositoryClass: OtmInterdictionRepository::class)]
#[ORM\Table(name: 'otm_interdiction')]
#[ORM\UniqueConstraint(name: 'uniq_otm_interdiction', columns: ['club_id', 'user_id', 'role'])]
#[ORM\Index(name: 'idx_otm_interdiction_user', columns: ['club_id', 'user_id'])]
class OtmInterdiction implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Code du poste interdit — @see AffectationMatch::ROLES */
    #[ORM\Column(length: 30)]
    private string $role = AffectationMatch::ROLE_ARBITRE_1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): self { $this->club = $club; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): self { $this->role = $role; return $this; }

    /** Libellé lisible du poste interdit (ex. « Arbitre 1 »). */
    public function getRoleLabel(): string
    {
        return AffectationMatch::ROLES[$this->role] ?? $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
