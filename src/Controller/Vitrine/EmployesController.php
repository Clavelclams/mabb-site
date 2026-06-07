<?php

declare(strict_types=1);

namespace App\Controller\Vitrine;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EmployesController — page publique de présentation des salariés MABB.
 *
 * Distinction avec l'organigramme (/club#organigramme) :
 *   - Organigramme : vue d'ensemble fonctionnelle (président, coordinateur, etc.)
 *   - Page Employés : focus sur les SALARIÉS (BPJEPS, alternants, services civiques, etc.)
 *     pour valoriser l'équipe permanente et la professionnalisation du club.
 *
 * Données HARDCODÉES en V1 pour la simplicité. À déplacer en BDD quand le besoin
 * de gestion dynamique (CMS) sera prouvé (probablement V2 quand un dirigeant
 * voudra mettre à jour sans toucher au code).
 *
 * Routé sur mabb.fr (cf. config/routes/vitrine.yaml).
 */
class EmployesController extends AbstractController
{
    #[Route('/employes', name: 'vitrine_employes', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('vitrine/employes/index.html.twig');
    }
}
