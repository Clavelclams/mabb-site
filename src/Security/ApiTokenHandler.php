<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Core\ApiTokenRepository;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * ApiTokenHandler — [B4 phase 1, 06/07/2026]
 *
 * Brancher sur l'authenticator NATIF `access_token` de Symfony (security.yaml,
 * firewall `api`). À chaque requête API, Symfony extrait le header
 * `Authorization: Bearer <jeton>` et nous demande à quel user il correspond.
 *
 * Chaîne : jeton clair → SHA-256 → lookup api_token → contrôle expiration
 * → UserBadge(email) résolu par le provider `app_user_provider` habituel.
 * Aucun état de session : chaque requête est authentifiée indépendamment
 * (stateless: true), exactement ce qu'attend une app mobile.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
    ) {}

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        $token = $this->tokens->findValide($accessToken);

        if ($token === null || $token->getUser()?->getEmail() === null) {
            // Message générique volontaire : ne pas révéler si le jeton est
            // inconnu, expiré ou orphelin (pas d'oracle pour un attaquant).
            throw new BadCredentialsException('Jeton d\'accès invalide ou expiré.');
        }

        return new UserBadge($token->getUser()->getEmail());
    }
}
