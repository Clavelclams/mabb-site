<?php

namespace App\Controller\Vitrine;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/compte')]
final class CompteController extends AbstractController
{
    #[Route('/se-connecter', name: 'vitrine_compte_se_connecter')]
    public function seConnecter(): Response
    {
        return $this->render('vitrine/compte/se_connecter.html.twig');
    }

    #[Route('/s-inscrire', name: 'vitrine_compte_s_inscrire')]
    public function sInscrire(): Response
    {
        return $this->render('vitrine/compte/s_inscrire.html.twig');
    }
}
