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
            return $this->redirect($this->retourSur($request));
        }

        $saison = $request->request->get('saison', '');

        if ($this->saisonService->isValide($saison)) {
            $request->getSession()->set('active_saison', $saison);
        }

        return $this->redirect($this->retourSur($request));
    }

    /**
     * [SÉCU 26/07] Retour vers la page précédente, mais UNIQUEMENT si elle est
     * sur le site. Avant, on renvoyait le Referer brut : un formulaire hébergé
     * sur un site malveillant faisait émettre par mabb.fr une redirection vers
     * ce site (open redirect, utile en hameçonnage : « le lien vient bien du
     * club »). On ne garde que le chemin, jamais le domaine.
     */
    private function retourSur(Request $request): string
    {
        $referer = (string) $request->headers->get('Referer', '');
        if ($referer === '') {
            return '/';
        }
        $chemin = parse_url($referer, PHP_URL_PATH);
        if (!is_string($chemin) || !str_starts_with($chemin, '/') || str_starts_with($chemin, '//')) {
            return '/';
        }
        $query = parse_url($referer, PHP_URL_QUERY);

        return $chemin . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
