<?php

namespace App\Controller\Vitrine;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccueilController extends AbstractController
{
    #[Route('/', name: 'vitrine_accueil')]
    public function index(): Response
    {
        return $this->render('vitrine/accueil/index.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/news', name: 'vitrine_news')]
    public function news(): Response
    {
        return $this->render('vitrine/accueil/news.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/club', name: 'vitrine_club')]
    public function club(): Response
    {
        return $this->render('vitrine/accueil/club.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/equipes', name: 'vitrine_equipes')]
    public function equipes(): Response
    {
        return $this->render('vitrine/accueil/equipes.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/galerie', name: 'vitrine_galerie')]
    public function galerie(): Response
    {
        return $this->render('vitrine/accueil/galerie.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/calendrier', name: 'vitrine_calendrier')]
    public function calendrier(): Response
    {
        return $this->render('vitrine/accueil/calendrier.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/numerique', name: 'vitrine_numerique')]
    public function numerique(): Response
    {
        return $this->render('vitrine/accueil/numerique.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/contact', name: 'vitrine_contact')]
    public function contact(): Response
    {
        return $this->render('vitrine/accueil/contact.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }
}
