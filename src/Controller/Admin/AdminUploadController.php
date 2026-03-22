<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class AdminUploadController extends AbstractController
{
    /**
     * Endpoint pour TinyMCE : reçoit un fichier image via POST (champ "file")
     * et retourne { "location": "/uploads/tinymce/nom-du-fichier.jpg" }
     * Format attendu par TinyMCE images_upload_handler.
     */
    #[Route('/admin/upload-image', name: 'admin_upload_image', methods: ['POST'])]
    public function uploadImage(Request $request, SluggerInterface $slugger): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['error' => 'Aucun fichier reçu.'], 400);
        }

        // Vérification du type MIME — images uniquement
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return $this->json(['error' => 'Type de fichier non autorisé.'], 415);
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = $slugger->slug($originalName);
        $newFilename  = $safeName . '-' . uniqid() . '.' . $file->guessExtension();

        try {
            $file->move(
                $this->getParameter('kernel.project_dir') . '/public/uploads/tinymce',
                $newFilename
            );
        } catch (FileException $e) {
            return $this->json(['error' => 'Erreur lors de l\'upload : ' . $e->getMessage()], 500);
        }

        // TinyMCE attend exactement { "location": "..." }
        return $this->json(['location' => '/uploads/tinymce/' . $newFilename]);
    }
}
