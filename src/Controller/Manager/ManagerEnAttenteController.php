<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page d'attente pour les Users dont la demande d'adhésion est PENDING.
 * Affichée par le PendingUserSubscriber qui redirige les Users non validés
 * ici, peu importe l'URL qu'ils tentent.
 *
 * UX : le user voit son statut, le rôle demandé, et a 2 actions :
 *   - Retour vitrine
 *   - Se déconnecter
 *
 * Pas d'accès aux données du club tant que l'admin n'a pas validé.
 */
class ManagerEnAttenteController extends AbstractController
{
    #[Route('/en-attente', name: 'manager_en_attente', methods: ['GET'])]
    public function enAttente(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_login');
        }

        // Collecte les demandes pending de l'user (théoriquement 1 seule en V1)
        $demandesPending = [];
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->isPending()) {
                $demandesPending[] = $ucr;
            }
        }

        // Si l'user n'a aucune demande pending (cas anormal vu qu'on est sur
        // cette page), on redirige vers le dashboard (le subscriber laissera passer).
        if (empty($demandesPending)) {
            return $this->redirectToRoute('manager_dashboard');
        }

        return $this->render('manager/en_attente.html.twig', [
            'demandes' => $demandesPending,
        ]);
    }
}
