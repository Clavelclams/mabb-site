<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * AdminLoginController — gère la page de connexion de l'espace /admin.
 *
 * Pourquoi un controller dédié ?
 * L'espace /admin est sur un firewall séparé "vitrine_admin" dans security.yaml.
 * Il a donc sa propre session et son propre formulaire de login.
 * Un utilisateur connecté sur la vitrine (ROLE_USER) n'est PAS connecté ici.
 */
#[Route('/admin')]
class AdminLoginController extends \Symfony\Bundle\FrameworkBundle\Controller\AbstractController
{
    /**
     * Page de connexion admin.
     *
     * IMPORTANT : Symfony intercepte le POST de ce formulaire via le firewall
     * "vitrine_admin" dans security.yaml (check_path: admin_login).
     * Cette méthode ne gère QUE l'affichage (GET) et les erreurs éventuelles.
     */
    #[Route('/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        // Si l'admin est déjà connecté, redirige vers la liste des articles
        if ($this->getUser()) {
            return $this->redirectToRoute('admin_articles_list');
        }

        return $this->render('admin/login.html.twig', [
            // Dernier email saisi (réinjecté dans le champ après une erreur)
            'last_username' => $authUtils->getLastUsername(),
            // Message d'erreur si identifiants incorrects (null si pas d'erreur)
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Déconnexion admin.
     *
     * Cette méthode ne sera JAMAIS exécutée.
     * Symfony intercepte la route "admin_logout" au niveau du firewall
     * et effectue la déconnexion avant que le controller ne soit appelé.
     * La méthode doit quand même exister pour que path('admin_logout') fonctionne.
     */
    #[Route('/deconnexion', name: 'admin_logout')]
    public function logout(): never
    {
        // Symfony intercepte cette route — ce code n'est jamais atteint
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement.');
    }
}
