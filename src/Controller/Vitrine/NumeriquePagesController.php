<?php

namespace App\Controller\Vitrine;

use App\Repository\Core\UserRepository;
use App\Repository\Vitrine\PageContenuRepository;
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
    public function formation(PageContenuRepository $pageRepo): Response
    {
        return $this->render('vitrine/formation/index.html.twig', [
            'pageContenu' => $pageRepo->findBySlug('formation'),
        ]);
    }

    #[Route('/cite-educative', name: 'vitrine_cite_educative')]
    public function citeEducative(PageContenuRepository $pageRepo): Response
    {
        return $this->render('vitrine/club/cite_educative.html.twig', [
            'pageContenu' => $pageRepo->findBySlug('cite-educative'),
        ]);
    }

    #[Route('/clavel', name: 'vitrine_clavel')]
    public function clavel(): Response
    {
        return $this->render('vitrine/clavel/index.html.twig');
    }

    #[Route('/projet-sport-etude', name: 'vitrine_projet_sport_etude')]
    public function projetSportEtude(PageContenuRepository $pageRepo): Response
    {
        return $this->render('vitrine/club/projet_sport_etude.html.twig', [
            'pageContenu' => $pageRepo->findBySlug('projet-sport-etude'),
        ]);
    }

    #[Route('/nos-reseaux', name: 'vitrine_nos_reseaux')]
    public function nosReseaux(): Response
    {
        return $this->render('vitrine/nos_reseaux/index.html.twig');
    }
}
