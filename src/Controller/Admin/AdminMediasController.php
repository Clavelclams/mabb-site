<?php

namespace App\Controller\Admin;

use App\Entity\Vitrine\Media;
use App\Repository\Vitrine\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/medias')]
class AdminMediasController extends AbstractController
{
    #[Route('', name: 'admin_medias_list')]
    public function index(MediaRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/medias/index.html.twig', [
            'medias' => $repo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/uploader', name: 'admin_medias_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if (!$this->isCsrfTokenValid('media_upload', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_medias_list');
        }

        $fichiers = $request->files->get('photos');

        if (!$fichiers) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToRoute('admin_medias_list');
        }

        // Normaliser en tableau (un ou plusieurs fichiers)
        if (!is_array($fichiers)) {
            $fichiers = [$fichiers];
        }

        $uploadCount = 0;

        foreach ($fichiers as $fichier) {
            if (!$fichier) {
                continue;
            }

            $extension = strtolower($fichier->guessExtension() ?? '');

            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                continue;
            }

            $nomOriginal = pathinfo($fichier->getClientOriginalName(), PATHINFO_FILENAME);
            $nomSafe     = $slugger->slug($nomOriginal) . '-' . uniqid() . '.' . $extension;

            $fichier->move(
                $this->getParameter('kernel.project_dir') . '/public/uploads/galerie',
                $nomSafe
            );

            $legende = trim($request->request->get('legende', ''));

            $media = new Media();
            $media->setNom($legende ?: $nomOriginal);
            $media->setPath($nomSafe);
            $media->setType(Media::TYPE_IMAGE);
            $media->setTaille($fichier->getSize());
            $media->setLegende($legende ?: null);

            $em->persist($media);
            $uploadCount++;
        }

        $em->flush();

        $this->addFlash('success', $uploadCount . ' photo(s) uploadée(s) ✅');

        return $this->redirectToRoute('admin_medias_list');
    }

    #[Route('/{id}/supprimer', name: 'admin_medias_delete', methods: ['POST'])]
    public function delete(Media $media, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid('delete_media_' . $media->getId(), $request->request->get('_csrf_token'))) {
            // Supprimer le fichier physique
            $path = $this->getParameter('kernel.project_dir') . '/public/uploads/galerie/' . $media->getPath();
            if (file_exists($path)) {
                unlink($path);
            }

            $em->remove($media);
            $em->flush();

            $this->addFlash('success', 'Photo supprimée.');
        }

        return $this->redirectToRoute('admin_medias_list');
    }
}
