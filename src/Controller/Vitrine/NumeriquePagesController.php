<?php

namespace App\Controller\Vitrine;

use App\Repository\Core\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NumeriquePagesController extends AbstractController
{
    #[Route('/membres', name: 'vitrine_membres')]
    public function membres(Request $request, UserRepository $userRepository): Response
    {
        $role = $request->query->get('role');
        $membres = $userRepository->findPublicMembers($role);

        return $this->render('vitrine/membres/index.html.twig', [
            'membres' => $membres,
            'roleActif' => $role,
        ]);
    }

    #[Route('/formation', name: 'vitrine_formation')]
    public function formation(): Response
    {
        return $this->render('vitrine/formation/index.html.twig');
    }

    #[Route('/cite-educative', name: 'vitrine_cite_educative')]
    public function citeEducative(): Response
    {
        return $this->render('vitrine/club/cite_educative.html.twig');
    }

    #[Route('/clavel', name: 'vitrine_clavel')]
    public function clavel(): Response
    {
        return $this->render('vitrine/clavel/index.html.twig');
    }
}
