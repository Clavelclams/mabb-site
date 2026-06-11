<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\CoachEquipeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * B5 — Affectation Coach ↔ Équipe (table de jointure).
 *
 * Un User (avec rôle COACH dans son UserClubRole) peut coacher
 * plusieurs équipes (ex: U13F + U15F principal + Séniors assistante).
 *
 * Une équipe peut avoir plusieurs coachs (principal + assistants).
 *
 * Le champ saison permet de tracer l'historique : Coach X était sur U13
 * en 2024-2025 puis sur U15 en 2025-2026.
 *
 * UNIQUE(user_id, equipe_id, saison) : un coach n'a qu'un seul rôle
 * sur la même équipe la même saison.
 */
#[ORM\Entity(repositoryClass: CoachEquipeRepository::class)]
#[ORM\Table(name: 'coach_equipe')]
#[ORM\UniqueConstraint(name: 'UNQ_CE_USER_EQUIPE_SAISON', columns: ['user_id', 'equipe_id', 'saison'])]
class CoachEquipe
{
    public const ROLE_PRINCIPAL = 'PRINCIPAL';
    public const ROLE_ASSISTANT = 'ASSISTANT';
    public const ROLES = [self::ROLE_PRINCIPAL, self::ROLE_ASSISTANT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 30)]
    private string $roleCoach = self::ROLE_PRINCIPAL;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $saison = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $e): self { $this->equipe = $e; return $this; }
    public function getRoleCoach(): string { return $this->roleCoach; }
    public function setRoleCoach(string $r): self { $this->roleCoach = $r; return $this; }
    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(?string $s): self { $this->saison = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function isPrincipal(): bool { return $this->roleCoach === self::ROLE_PRINCIPAL; }
}
