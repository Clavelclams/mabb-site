<?php

namespace App\Controller\Admin;

use App\Entity\Vitrine\PageContenu;
use App\Repository\Vitrine\PageContenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

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
    public function edit(
        PageContenu $page,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('page_edit_' . $page->getId(), $request->request->get('_csrf_token'))) {

                // Champs texte
                $page->setSousTitre(trim($request->request->get('sousTitre', '')));
                $page->setContenu($request->request->get('contenu', ''));
                $page->setCouleurTexte($request->request->get('couleurTexte', '#ffffff') ?: '#ffffff');

                // Upload image de couverture
                $imageFile = $request->files->get('image');
                if ($imageFile) {
                    $originalName = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeName     = $slugger->slug($originalName);
                    $newFilename  = $safeName . '-' . uniqid() . '.' . $imageFile->guessExtension();

                    try {
                        $imageFile->move(
                            $this->getParameter('kernel.project_dir') . '/public/uploads/pages',
                            $newFilename
                        );
                        $page->setImagePath($newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('danger', '⚠️ Erreur lors de l\'upload de l\'image : ' . $e->getMessage());
                    }
                }

                $em->flush();

                $this->addFlash('success', '✅ Page "' . $page->getPageNom() . '" mise à jour.');
                return $this->redirectToRoute('admin_pages_list');
            }

            $this->addFlash('danger', '❌ Token CSRF invalide. Veuillez réessayer.');
        }

        return $this->render('admin/pages/edit.html.twig', ['page' => $page]);
    }
}
