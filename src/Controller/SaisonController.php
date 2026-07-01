<?php

namespace App\Controller;

use App\Service\SaisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SaisonController extends AbstractController
{
    public function __construct(private SaisonService $saisonService) {}

    #[Route('/saison/changer', name: 'saison_changer', methods: ['POST'])]
    public function changer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('saison_changer', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirect($request->headers->get('Referer', '/'));
        }

        $saison = $request->request->get('saison', '');

        if ($this->saisonService->isValide($saison)) {
            $request->getSession()->set('active_saison', $saison);
        }

        return $this->redirect($request->headers->get('Referer', '/'));
    }
}
