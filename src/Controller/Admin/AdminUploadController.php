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
        // [SÉCU 26/07] Était 'ROLE_ADMIN' : ce rôle n'existe NULLE PART dans le
        // projet (hiérarchie = SUPER_ADMIN > DIRIGEANT > COACH > USER). Le
        // contrôle échouait donc toujours — un contrôle fantôme. La vraie
        // barrière est l'access_control ^/admin, on l'aligne explicitement.
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $file = $request->files->get('file');

        if (!$file) {
            return $this->json(['error' => 'Aucun fichier reçu.'], 400);
        }

        // [SÉCU 26/07] SVG RETIRÉ de la liste : un SVG est un document XML qui
        // peut embarquer du <script>. Servi depuis /uploads/, il s'exécute sur
        // l'origine mabb.fr (vol de session admin). L'extension est désormais
        // imposée par le serveur d'après le MIME, plus déduite du fichier.
        $extensionsParMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
        ];
        $mime = $file->getMimeType() ?? '';
        if (!isset($extensionsParMime[$mime])) {
            return $this->json(['error' => 'Type de fichier non autorisé.'], 415);
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName     = $slugger->slug($originalName);
        $newFilename  = $safeName . '-' . bin2hex(random_bytes(8)) . '.' . $extensionsParMime[$mime];

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
