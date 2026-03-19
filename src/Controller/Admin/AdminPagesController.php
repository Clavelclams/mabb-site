<?php

namespace App\Controller\Admin;

use App\Entity\Vitrine\PageContenu;
use App\Repository\Vitrine\PageContenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/pages')]
class AdminPagesController extends AbstractController
{
    #[Route('', name: 'admin_pages_list')]
    public function index(PageContenuRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/pages/index.html.twig', [
            'pages' => $repo->findAll(),
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_pages_edit', methods: ['GET', 'POST'])]
    public function edit(PageContenu $page, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('page_edit_' . $page->getId(), $request->request->get('_csrf_token'))) {
                $page->setSousTitre(trim($request->request->get('sousTitre', '')));
                $page->setContenu($request->request->get('contenu', ''));
                $em->flush();

                $this->addFlash('success', '✅ Page "' . $page->getPageNom() . '" mise à jour.');
                return $this->redirectToRoute('admin_pages_list');
            }

            $this->addFlash('danger', '❌ Token CSRF invalide. Veuillez réessayer.');
        }

        return $this->render('admin/pages/edit.html.twig', ['page' => $page]);
    }
}
