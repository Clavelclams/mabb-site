<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use Symfony\Component\HttpFoundation\Response;

/**
 * [VC-1 04/08/2026] Erreur métier de l'API club, porteuse de son code HTTP.
 *
 * POURQUOI UNE EXCEPTION PLUTÔT QU'UN RETOUR :
 *   La résolution du club et le contrôle des rôles ont lieu au tout début de
 *   chaque endpoint. Les faire remonter par exception évite de répéter
 *   `if (erreur) return ...` partout, et surtout garantit qu'on ne peut PAS
 *   oublier de traiter le cas : sans club résolu, le code métier ne s'exécute
 *   jamais. C'est un choix de sécurité, pas de confort.
 *
 * Elle est convertie en réponse JSON par ApiClubExceptionListener.
 */
final class ApiClubException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = Response::HTTP_BAD_REQUEST,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
