<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\ParentInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B30c 12/06/2026] Invitation envoyée par mail à un parent pas encore inscrit.
 *
 * Le token clair n'est JAMAIS stocké en BDD : on stocke uniquement son hash SHA-256.
 * Le token clair n'apparaît qu'une fois, dans le mail envoyé au parent.
 *
 * Cycle de vie :
 *   1. Staff/joueuse envoie invit → token clair généré (random_bytes(32))
 *   2. Hash stocké en BDD, token clair envoyé par mail au parent
 *   3. Parent clique le lien /parent-invitation/{token_clair}
 *   4. Si valide (expires_at > now ET accepted_at IS NULL) → form signup
 *   5. Création User + UserClubRole + ParentJoueur ACTIVE + accepted_at set
 *
 * Expiration : 14j (laisse le temps au parent de réagir).
 */
#[ORM\Entity(repositoryClass: ParentInvitationRepository::class)]
#[ORM\Table(name: 'parent_invitation')]
#[ORM\Index(columns: ['token_hash'], name: 'IDX_PI_TOKEN')]
class ParentInvitation
{
    public const TTL_DAYS = 14;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private ?string $tokenHash = null;

    #[ORM\Column(length: 180)]
    private ?string $emailCible = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Qui a envoyé l'invit : staff ou la joueuse elle-même. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $demandeur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** User créé suite à l'acceptation (le parent qui s'inscrit). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptedUser = null;

    public function __construct(string $tokenHash, string $emailCible, Joueur $joueur, ?User $demandeur)
    {
        $this->tokenHash = $tokenHash;
        $this->emailCible = strtolower(trim($emailCible));
        $this->joueur = $joueur;
        $this->demandeur = $demandeur;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+' . self::TTL_DAYS . ' days');
    }

    public function getId(): ?int { return $this->id; }
    public function getTokenHash(): ?string { return $this->tokenHash; }
    public function getEmailCible(): ?string { return $this->emailCible; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function getDemandeur(): ?User { return $this->demandeur; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getAcceptedAt(): ?\DateTimeImmutable { return $this->acceptedAt; }
    public function getAcceptedUser(): ?User { return $this->acceptedUser; }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isAccepted();
    }

    public function accepter(User $user): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
        $this->acceptedUser = $user;
    }
}
