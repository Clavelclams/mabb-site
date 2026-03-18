<?php

namespace App\Controller\Admin;

use App\Repository\Core\UserRepository;
use App\Entity\Core\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion des rôles vitrine par les super admins.
 * Accès strictement limité à ROLE_SUPER_ADMIN.
 * Périmètre volontairement réduit : prénom, nom, email, rôles — PAS de bio ni photo.
 */
#[Route('/admin')]
class AdminRolesController extends AbstractController
{
    private const ROLES_VALIDES = ['coach', 'staff', 'dirigeant', 'service-civique', 'joueur', 'parent'];

    #[Route('/utilisateurs', name: 'admin_utilisateurs')]
    public function liste(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $utilisateurs = $userRepository->findBy([], ['nom' => 'ASC', 'prenom' => 'ASC']);

        return $this->render('admin/roles/liste.html.twig', [
            'utilisateurs' => $utilisateurs,
        ]);
    }

    #[Route('/utilisateur/{id}/roles', name: 'admin_utilisateur_roles', methods: ['GET', 'POST'])]
    public function editerRoles(
        int $id,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $utilisateur = $userRepository->find($id);
        if (!$utilisateur) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_roles_' . $id, $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_utilisateur_roles', ['id' => $id]);
            }

            $rolesCoches = $request->request->all('rolesMembre') ?? [];
            $rolesCoches = array_filter($rolesCoches, fn($r) => in_array($r, self::ROLES_VALIDES));
            // setRolesMembre() force toujours 'benevole' en premier
            $utilisateur->setRolesMembre(array_values($rolesCoches));

            $em->flush();
            $this->addFlash('success', sprintf('Rôles de %s mis à jour ✅', $utilisateur->getPrenom()));

            return $this->redirectToRoute('admin_utilisateurs');
        }

        return $this->render('admin/roles/editer.html.twig', [
            'utilisateur' => $utilisateur,
            'rolesValides' => self::ROLES_VALIDES,
        ]);
    }
}
