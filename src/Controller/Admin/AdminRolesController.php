<?php

namespace App\Controller\Admin;

use App\Entity\Core\User;
use App\Repository\Core\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminRolesController extends AbstractController
{
    #[Route('/utilisateurs', name: 'admin_users_list')]
    public function index(UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $users = $userRepository->findBy([], ['prenom' => 'ASC']);

        return $this->render('admin/roles/index.html.twig', [
            'users' => $users,
        ]);
    }

    #[Route('/utilisateur/{id}/roles', name: 'admin_user_roles_edit', methods: ['POST'])]
    public function editRoles(
        User $user,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid('edit_roles_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_users_list');
        }

        $rolesValides = ['coach', 'staff', 'dirigeant', 'service-civique', 'joueur', 'parent'];
        $rolesChoisis = $request->request->all('rolesMembre') ?? [];
        $rolesFiltres = array_filter($rolesChoisis, fn($r) => in_array($r, $rolesValides));
        // setRolesMembre force toujours benevole
        $user->setRolesMembre(array_values($rolesFiltres));

        $em->flush();
        $this->addFlash('success', 'Rôles de ' . $user->getPrenom() . ' mis à jour ✅');

        return $this->redirectToRoute('admin_users_list');
    }
}
