<?php

declare(strict_types=1);

namespace App\Controller\Vitrine;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LegalController — [06/07/2026] pages légales obligatoires (CNIL / LCEN).
 *
 * Deux pages statiques :
 *   GET /mentions-legales           → éditeur, hébergeur, propriété intellectuelle
 *   GET /politique-confidentialite  → RGPD : données, finalités, droits
 *
 * Les informations variables (adresse du siège, directeur de publication,
 * email de contact) sont des blocs cms() → modifiables depuis /admin/contenus
 * sans toucher au code.
 */
class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'vitrine_mentions_legales', methods: ['GET'])]
    public function mentionsLegales(): Response
    {
        return $this->render('vitrine/legal/mentions_legales.html.twig');
    }

    #[Route('/politique-confidentialite', name: 'vitrine_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('vitrine/legal/confidentialite.html.twig');
    }
}
