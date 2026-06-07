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

    #[Route('/manager', name: 'vitrine_manager_construction')]
    public function managerConstruction(): Response
    {
        return $this->render('vitrine/construction/manager.html.twig');
    }

    /**
     * /pirb → redirect 301 vers https://pirb.mabb.fr
     *
     * Le sous-domaine PIRB est désormais en production. Toute requête sur
     * vitrine.mabb.fr/pirb (anciens liens, partages, Google) est redirigée
     * de manière permanente (301) vers le bon domaine.
     *
     * Le nom de route `vitrine_pirb_construction` est conservé pour ne pas
     * casser les éventuels appels {{ path('vitrine_pirb_construction') }}
     * qui traîneraient encore.
     */
    #[Route('/pirb', name: 'vitrine_pirb_construction')]
    public function pirbConstruction(): Response
    {
        return $this->redirect('https://pirb.mabb.fr', Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/nos-reseaux', name: 'vitrine_nos_reseaux')]
    public function nosReseaux(): Response
    {
        return $this->render('vitrine/nos_reseaux/index.html.twig');
    }
}
