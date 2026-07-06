<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * ApiToken — [B4 phase 1, 06/07/2026] jeton d'accès de l'API mobile PIRB.
 *
 * POURQUOI PAS LexikJWT (pourtant cité par ADR-0007) :
 * l'installation de dépendances Composer est impossible dans les sessions
 * IA sandbox et risquée sans exécution locale. Symfony 6.2+ fournit
 * NATIVEMENT l'authenticator `access_token` : des jetons opaques stockés
 * en base font le même travail pour la phase 1, avec en bonus la
 * RÉVOCATION immédiate (impossible avec un JWT sans liste noire).
 * La migration vers JWT reste ouverte quand B4 phase 2 installera
 * API Platform (cf. ADR-0010).
 *
 * SÉCURITÉ :
 *   - Le jeton en clair n'est montré qu'UNE fois (réponse du login).
 *   - En base on ne stocke que son hash SHA-256 → un dump de la table
 *     ne permet pas de rejouer les jetons.
 *   - Expiration 30 jours, renouvelée par re-login (pas de refresh token
 *     en phase 1 — assumé, l'app re-loguera).
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_token')]
#[ORM\Index(name: 'idx_api_token_hash', columns: ['token_hash'])]
class ApiToken
{
    public const VALIDITE = '+30 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** SHA-256 hex du jeton (jamais le jeton en clair). */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    /** Libellé libre de l'appareil ("Pixel 7 de Naomi") — debug/révocation ciblée. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $appareil = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable(self::VALIDITE);
    }

    /**
     * Fabrique un jeton : retourne [entité, jeton EN CLAIR à renvoyer au client].
     * 32 octets aléatoires → 64 hex. Le clair n'est jamais persisté.
     *
     * @return array{0: self, 1: string}
     */
    public static function creerPour(User $user, ?string $appareil = null): array
    {
        $clair = bin2hex(random_bytes(32));
        $token = new self();
        $token->user      = $user;
        $token->tokenHash = hash('sha256', $clair);
        $token->appareil  = $appareil !== null ? mb_substr($appareil, 0, 100) : null;
        return [$token, $clair];
    }

    public static function hashDe(string $clair): string
    {
        return hash('sha256', $clair);
    }

    public function estExpire(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function getAppareil(): ?string { return $this->appareil; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
