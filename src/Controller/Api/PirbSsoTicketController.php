<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbSsoTicketController — [UX V1, 10/07/2026] émission du ticket SSO.
 *
 * Moitié API du pont app → web (l'autre moitié, la consommation, vit dans
 * Pirb\SsoController — voir sa doc pour le pourquoi/comment complet).
 * L'app appelle ceci avec son Bearer, reçoit un ticket signé HMAC valable
 * 90 s, et ouvre la WebView sur /sso/pirb?ticket=…&cible=… → la joueuse ne
 * se connecte plus qu'UNE fois, dans l'app.
 */
class PirbSsoTicketController extends AbstractController
{
    private const VALIDITE_S = 90;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    #[Route('/api/pirb/sso/ticket', name: 'api_pirb_sso_ticket', methods: ['POST'])]
    public function ticket(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        // Ticket = "userId.exp.signature" — même format que la vérification
        // (Pirb\SsoController::consommer). HMAC avec %kernel.secret% : les
        // DEUX côtés utilisent le même secret, aucune table nécessaire.
        $exp = time() + self::VALIDITE_S;
        $payload = $user->getId() . '.' . $exp;
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return new JsonResponse(['ticket' => $payload . '.' . $signature]);
    }
}
