<?php

namespace App\Controller\Manager;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * ManagerLoginController — authentification pour manager.mabb.fr.
 *
 * Ce controller est routé uniquement sur le host manager.mabb.fr
 * via config/routes/manager.yaml (contrainte host).
 *
 * Le firewall "manager" dans security.yaml intercepte les POST
 * sur /login pour traiter l'authentification automatiquement.
 */
class ManagerLoginController extends AbstractController
{
    /**
     * Page de connexion — manager.mabb.fr/login
     *
     * GET  → affiche le formulaire de login
     * POST → intercepté par Symfony (firewall "manager"), jamais traité ici
     */
    #[Route('/login', name: 'manager_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        // Déjà connecté → accueil manager
        if ($this->getUser()) {
            return $this->redirectToRoute('manager_dashboard');
        }

        return $this->render('manager/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Dashboard principal — manager.mabb.fr/
     *
     * Point d'entrée après connexion. Protégé par access_control (ROLE_USER min).
     * Cette page sera enrichie au fil des sprints (affichage du club, équipes...).
     */
    #[Route('/', name: 'manager_dashboard')]
    public function dashboard(): Response
    {
        // Vérifie l'accès (double sécurité, en complément de l'access_control)
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('manager/dashboard.html.twig');
    }

    /**
     * Déconnexion — interceptée automatiquement par Symfony.
     * Cette méthode n'est jamais exécutée, la route doit juste exister.
     */
    #[Route('/deconnexion', name: 'manager_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement.');
    }
}
