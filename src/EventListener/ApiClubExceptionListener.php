<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Controller\Api\Club\ApiClubException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * [VC-1 04/08/2026] Convertit une ApiClubException en réponse JSON propre.
 *
 * Sans ce listener, une exception métier de l'API club remonterait comme une
 * erreur 500 avec une page HTML — illisible pour une application mobile, et
 * bavarde en environnement de développement.
 *
 * Portée volontairement étroite : on ne touche QUE les ApiClubException, et
 * seulement sur les routes /api/club/. Le reste du site garde son traitement
 * d'erreur habituel.
 */
#[AsEventListener(event: ExceptionEvent::class)]
final class ApiClubExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ApiClubException) {
            return;
        }

        // Ceinture et bretelles : même si l'exception venait d'ailleurs, on ne
        // répond en JSON que sur le périmètre de l'API club.
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/club')) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $exception->getMessage()],
            $exception->getStatusCode()
        ));
    }
}
