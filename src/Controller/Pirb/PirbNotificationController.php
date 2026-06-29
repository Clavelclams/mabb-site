<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Repository\Core\NotificationRepository;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * PirbNotificationController
 *
 * Page de notifications in-app de la joueuse.
 *
 * Route :
 *   GET /notifications (pirb_notif_index)
 *
 * Comportement :
 *   Ouvrir la page marque automatiquement TOUTES les notifications comme lues.
 *   C'est le comportement standard (comme les notifs mobiles) :
 *   l'acte de regarder = marquer lu. Le badge disparaît donc après visite.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class PirbNotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly TenantResolver         $tenantResolver,
    ) {}

    #[Route('/notifications', name: 'pirb_notif_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $club = $this->tenantResolver->getCurrentClub();

        if ($club === null) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Charger les notifs AVANT de les marquer lues
        // → le premier rendu montre l'état non-lu/lu correct
        $notifications = $this->notifRepo->findByUserAndClub($user, $club);

        // Marquer tout lu à l'ouverture de la page
        $this->notifRepo->markAllReadByUserAndClub($user, $club);

        return $this->render('pirb/notifications.html.twig', [
            'notifications' => $notifications,
        ]);
    }
}
