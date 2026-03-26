<?php

namespace App\Controller\Pirb;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * PirbLoginController — authentification pour pirb.mabb.fr.
 *
 * Ce controller est routé uniquement sur le host pirb.mabb.fr
 * via config/routes/pirb.yaml (contrainte host).
 *
 * PIRB = espace joueur individuel : stats perso, shot chart, profil.
 * Accès : tout utilisateur authentifié (ROLE_USER minimum).
 */
class PirbLoginController extends AbstractController
{
    /**
     * Page de connexion — pirb.mabb.fr/login
     *
     * GET  → affiche le formulaire
     * POST → intercepté par le firewall "pirb" dans security.yaml
     */
    #[Route('/login', name: 'pirb_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        // Déjà connecté → tableau de bord joueur
        if ($this->getUser()) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        return $this->render('pirb/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Dashboard joueur — pirb.mabb.fr/
     *
     * Point d'entrée après connexion. Protégé par access_control (ROLE_USER).
     * Sera enrichi en Phase 4 (stats perso, shot chart, timeline, feedback).
     */
    #[Route('/', name: 'pirb_dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('pirb/dashboard.html.twig');
    }

    /**
     * Déconnexion — interceptée automatiquement par Symfony.
     * Cette méthode n'est jamais exécutée, la route doit juste exister.
     */
    #[Route('/deconnexion', name: 'pirb_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement.');
    }
}
