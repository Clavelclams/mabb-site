<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Vitrine\BlocContenu;
use App\Repository\Vitrine\BlocContenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * AdminContenusController — [CMS V2 05/07/2026]
 *
 * Back-office des BLOCS de contenu vitrine (textes, paragraphes, images).
 * Complète AdminPagesController (pages Markdown entières) avec la
 * granularité bloc : chaque titre/texte/photo balisé cms() dans les
 * templates devient éditable ici, groupé par page.
 *
 * Les clés apparaissent AUTOMATIQUEMENT après le premier affichage des
 * pages du site (auto-enregistrement par CmsExtension) — si la liste est
 * vide, il suffit de visiter le site puis recharger.
 *
 * Sécurité : ROLE_SUPER_ADMIN (même modèle que le reste de /admin).
 */
#[Route('/admin/contenus')]
class AdminContenusController extends AbstractController
{
    #[Route('', name: 'admin_contenus_list')]
    public function index(BlocContenuRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/contenus/index.html.twig', [
            'groupes' => $repo->groupesParPage(),
        ]);
    }

    /**
     * Enregistre UN bloc (texte ou image). POST classique depuis le
     * formulaire inline de la liste — pas d'AJAX, simplicité d'abord.
     */
    #[Route('/{id}/modifier', name: 'admin_contenus_edit', methods: ['POST'])]
    public function edit(
        BlocContenu $bloc,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid('bloc_edit_' . $bloc->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', '❌ Token CSRF invalide.');
            return $this->redirectToRoute('admin_contenus_list');
        }

        if ($bloc->getType() === BlocContenu::TYPE_IMAGE) {
            $imageFile = $request->files->get('image');
            if ($imageFile) {
                $safeName    = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = $safeName . '-' . uniqid() . '.' . $imageFile->guessExtension();
                try {
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/cms',
                        $newFilename
                    );
                    $bloc->setValeur('uploads/cms/' . $newFilename);
                } catch (FileException $e) {
                    $this->addFlash('danger', '⚠️ Upload impossible : ' . $e->getMessage());
                    return $this->redirectToRoute('admin_contenus_list');
                }
            }
        } else {
            $bloc->setValeur($request->request->get('valeur', ''));
        }

        $em->flush();
        $this->addFlash('success', '✅ Bloc « ' . $bloc->getCle() . ' » mis à jour.');
        return $this->redirectToRoute('admin_contenus_list', ['_fragment' => 'bloc-' . $bloc->getId()]);
    }

    /** Réinitialise un bloc au défaut du template (valeur → NULL). */
    #[Route('/{id}/reinitialiser', name: 'admin_contenus_reset', methods: ['POST'])]
    public function reset(BlocContenu $bloc, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid('bloc_reset_' . $bloc->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', '❌ Token CSRF invalide.');
            return $this->redirectToRoute('admin_contenus_list');
        }

        $bloc->setValeur(null);
        $em->flush();
        $this->addFlash('success', '↩️ Bloc « ' . $bloc->getCle() . ' » réinitialisé au texte d\'origine.');
        return $this->redirectToRoute('admin_contenus_list', ['_fragment' => 'bloc-' . $bloc->getId()]);
    }
}
