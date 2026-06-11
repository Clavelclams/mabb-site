<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\ResetPasswordRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * B1 — Sécu jury : demande de réinitialisation de mot de passe.
 *
 * RÈGLE DE SÉCURITÉ : le token clair n'est JAMAIS stocké ici.
 * On stocke uniquement son hash SHA-256.
 *
 * Cycle de vie :
 *   1. User clique "Mot de passe oublié" → on génère un token clair (64 chars hex)
 *   2. On envoie le token clair par mail (lien /reinitialiser/{token})
 *   3. On stocke en BDD seulement sha256(token) + expiresAt (now + 1h)
 *   4. Quand le user clique le lien, on hash le token reçu et on cherche
 *      par tokenHash + expiresAt > now + consumedAt = null
 *   5. À la validation : on set consumedAt = now (jamais réutilisable)
 *
 * Cleanup : un cron ou le service supprime périodiquement les entrées
 * dont expiresAt < now (passées + jamais consommées).
 *
 * Multi-tenant : un User peut avoir des rôles dans plusieurs clubs.
 * Le reset password est lié au User (pas à un club), donc cette table
 * n'a PAS de club_id (intentionnel — sinon on ne pourrait pas reset
 * un user multi-clubs).
 */
#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
#[ORM\Table(name: 'reset_password_request')]
#[ORM\Index(columns: ['token_hash'], name: 'IDX_RPR_TOKEN')]
#[ORM\Index(columns: ['expires_at'], name: 'IDX_RPR_EXPIRES')]
class ResetPasswordRequest
{
    /** Durée de validité d'un token : 1h. */
    public const TTL_SECONDS = 3600;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * User qui a demandé le reset.
     * onDelete CASCADE : si on supprime le User, ses demandes
     * sont automatiquement nettoyées.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Hash SHA-256 du token (64 caractères hex).
     * JAMAIS le token en clair.
     */
    #[ORM\Column(length: 64)]
    private ?string $tokenHash = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $expiresAt = null;

    /**
     * Date à laquelle le token a été utilisé pour reset le password.
     * null = pas encore consommé.
     * Une fois consommé, le token devient inutilisable même si expiresAt > now.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    /** IP qui a fait la demande (audit log RFC 4632 : max 45 chars pour IPv6). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $requestIp = null;

    public function __construct(User $user, string $tokenHash, ?string $requestIp = null)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->requestedAt = new \DateTimeImmutable();
        $this->expiresAt = $this->requestedAt->modify('+' . self::TTL_SECONDS . ' seconds');
        $this->requestIp = $requestIp;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function getRequestedAt(): ?\DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function consume(): self
    {
        $this->consumedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isConsumed();
    }

    public function getRequestIp(): ?string
    {
        return $this->requestIp;
    }
}
