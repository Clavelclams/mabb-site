<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\Club;
use App\Entity\Core\Notification;
use App\Entity\Core\User;
use App\Repository\Core\NotificationRepository;
use App\Repository\Sport\JoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiNotificationController — [Bloc F, 13/07/2026]
 *
 * Les notifications de la joueuse, en natif.
 *
 *   GET  /api/pirb/notifications      → { nonLues, notifications[] }
 *   POST /api/pirb/notifications/lues → tout marquer lu → { nonLues: 0 }
 *
 * DIFFÉRENCE ASSUMÉE AVEC LA PAGE WEB : côté web, ouvrir /notifications marque
 * automatiquement tout comme lu, dans le même GET. C'est pratique mais c'est une
 * faute : un GET doit être « sûr » (safe), c'est-à-dire ne RIEN modifier côté
 * serveur. Sinon un simple rechargement, un préchargement du navigateur ou un
 * retry réseau (et on en a ajouté un dans l'app le 12/07) modifie les données
 * sans que personne ne l'ait demandé.
 * Ici, on sépare : le GET lit, le POST marque lu. L'app appelle le POST quand
 * l'écran s'affiche vraiment. Même résultat pour l'utilisatrice, comportement
 * correct pour la machine.
 *
 * ISOLATION MULTI-CLUB : le club vient de la fiche Joueur du user connecté (et
 * pas d'un paramètre de requête). Impossible de demander les notifications d'un
 * autre club en bricolant l'URL.
 *
 * CONTRAT : miroir de `Pirb store/src/types/pirb.ts`. Toute évolution = les DEUX.
 */
class PirbApiNotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly JoueurRepository $joueurRepo,
    ) {}

    #[Route('/api/pirb/notifications', name: 'api_pirb_notifications', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $contexte = $this->contexte();
        if ($contexte instanceof JsonResponse) { return $contexte; }
        [$user, $club] = $contexte;

        $notifications = $this->notifRepo->findByUserAndClub($user, $club);

        return new JsonResponse([
            'nonLues'       => $this->notifRepo->countUnreadByUserAndClub($user, $club),
            'notifications' => array_map(
                fn (Notification $n) => [
                    'id'        => $n->getId(),
                    'type'      => $n->getType(),
                    'libelle'   => $n->getLibelle(),
                    'message'   => $n->getMessage(),
                    'couleur'   => $n->getCouleur(), // 'success' | 'danger' | 'neutral'
                    'lue'       => $n->isLue(),
                    'createdAt' => $n->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $notifications,
            ),
        ]);
    }

    #[Route('/api/pirb/notifications/lues', name: 'api_pirb_notifications_lues', methods: ['POST'])]
    public function marquerLues(): JsonResponse
    {
        $contexte = $this->contexte();
        if ($contexte instanceof JsonResponse) { return $contexte; }
        [$user, $club] = $contexte;

        $this->notifRepo->markAllReadByUserAndClub($user, $club);

        return new JsonResponse(['nonLues' => 0]);
    }

    /**
     * Le user connecté + SON club (déduit de sa fiche Joueur).
     *
     * @return array{0: User, 1: Club}|JsonResponse
     */
    private function contexte(): array|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        $club = $joueur?->getClub();
        if ($club === null) {
            return new JsonResponse(
                ['error' => 'Aucun club lié à ce compte. Contacte le staff.'],
                Response::HTTP_NOT_FOUND
            );
        }

        return [$user, $club];
    }
}
