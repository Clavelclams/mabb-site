<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccueilController extends AbstractController
{
    // Page 1 - Accueil (index)
    #[Route('/', name: 'app_accueil')]
    public function index(): Response
    {
        // render affiche la template qu'il y a dans la parenthèse 
        return $this->render('accueil/index.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

        // Page 2 - Actualités (news)
    #[Route('/news', name: 'app_news')]
    public function news(): Response
    {
        return $this->render('accueil/news.html.twig', [
            'controller_name' => 'newsController',
        ]);
    }


        // Page 3 - Le Club (club)
    #[Route('/club', name: 'app_club')]
    public function club(): Response
    {
        return $this->render('accueil/club.html.twig', [
            'controller_name' => 'clubController',
        ]);
    }


        // Page 4 - Equipes / Entraînements (equipes)
    #[Route('/equipes', name: 'app_equipes')]
    public function equipes(): Response
    {
        return $this->render('accueil/equipes.html.twig', [
            'controller_name' => 'equioesController',
        ]);
    }


        // Page 5 - Galerie (galerie)
    #[Route('/galerie', name: 'app_galerie')]
    public function galerie(): Response
    {
        return $this->render('accueil/galerie.html.twig', [
            'controller_name' => 'galerieController',
        ]);
    }


        // Page 6 - Calendrier / Evenement / Match (calendrier)
    #[Route('/calendrier', name: 'app_calendrier')]
    public function calendrier(): Response
    {
        return $this->render('accueil/calendrier.html.twig', [
            'controller_name' => 'calendrierController',
        ]);
    }


        // Page 7 - Espace numérique (numerique)
    #[Route('/numerique', name: 'app_numerique')]
    public function numerique(): Response
    {
        return $this->render('accueil/numerique.html.twig', [
            'controller_name' => 'numeriqueController',
        ]);
    }


        // Page 8 - Contact (contact)
    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('accueil/contact.html.twig', [
            'controller_name' => 'contactController',
        ]);
    }

}
