<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\Joueur;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des photos de profil joueurs — PIRB Phase C (#58).
 *
 * Responsabilités :
 *   - Validation MIME/taille (images uniquement, 2 Mo max)
 *   - Stockage physique dans public/uploads/joueurs/
 *   - Update du champ photoPath du Joueur
 *   - Suppression de l'ancienne photo si remplacement
 *
 * Le SERVICE NE FAIT PAS LE CONTRÔLE DE SÉCURITÉ. C'est au controller
 * de vérifier que l'User authentifié a le droit de modifier ce Joueur
 * (== Joueur.user pour PIRB self-edit, ou ROLE STAFF/COACH/DIRIGEANT
 * du club pour Manager).
 *
 * Pattern aligné sur JustificatifNoteFraisUploader / RencontrePdfUploader.
 *
 * STOCKAGE :
 *   public/uploads/joueurs/{joueurId}_{uniqid}.{ext}
 *
 *   L'uniqid garantit qu'une nouvelle upload écrase pas le cache navigateur
 *   du HTML précédent (CDN-safe).
 */
final class JoueurPhotoUploader
{
    /** Types MIME images acceptés → extension utilisée pour le filename */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    /** 2 Mo — assez pour une photo de qualité mais pas un méga RAW */
    private const MAX_SIZE = 2 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/joueurs/ (cf services.yaml) */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload la photo, met à jour Joueur.photoPath, supprime l'ancienne si existante.
     *
     * @throws \InvalidArgumentException Si validation échoue (mime, taille)
     * @throws FileException             Si déplacement physique échoue
     * @throws \LogicException           Si le Joueur n'a pas d'ID (jamais persisté)
     */
    public function upload(UploadedFile $file, Joueur $joueur): void
    {
        $this->validate($file);

        $joueurId = $joueur->getId();
        if ($joueurId === null) {
            throw new \LogicException('Le joueur doit être persisté (avoir un ID) avant upload photo.');
        }

        // Capture AVANT move() — bug Symfony connu (UploadedFile devient invalide après move)
        $mime      = $file->getMimeType() ?? '';
        $extension = self::ALLOWED_MIME_TYPES[$mime];

        // Suppression de l'ancienne photo si présente (évite l'accumulation de fichiers orphelins)
        $this->deletePreviousFile($joueur);

        if (!is_dir($this->uploadBaseDirectory)) {
            mkdir($this->uploadBaseDirectory, 0755, true);
        }

        // Format : {joueurId}_{uniqid}.{ext}
        // L'uniqid garantit cache-busting si nouvelle photo (le path change)
        $filename = sprintf('%d_%s.%s', $joueurId, uniqid('', false), $extension);
        $file->move($this->uploadBaseDirectory, $filename);

        // Path stocké en BDD = relatif depuis public/ (pour servir via asset())
        $joueur->setPhotoPath('uploads/joueurs/' . $filename);
    }

    /**
     * Supprime physiquement le fichier photo actuel du joueur (si existant)
     * SANS toucher au champ photoPath (à faire côté controller si besoin).
     */
    public function deletePreviousFile(Joueur $joueur): void
    {
        $currentPath = $joueur->getPhotoPath();
        if ($currentPath === null || $currentPath === '') {
            return;
        }

        // Sécurité : empêcher path traversal (le path stocké est uploads/joueurs/xxx,
        // on extrait juste le filename)
        $basename = basename($currentPath);
        if ($basename === '' || str_contains($basename, '..')) {
            return;
        }

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $basename;
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Validation MIME + taille. Lève InvalidArgumentException avec message clair
     * pour affichage au user.
     */
    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Photo trop volumineuse (%.1f Mo). Maximum : 2 Mo.',
                $file->getSize() / 1024 / 1024
            ));
        }

        $mime = $file->getMimeType();
        if (!array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException(sprintf(
                'Format non autorisé (%s). Formats acceptés : JPG, PNG, WebP, HEIC.',
                $mime ?? 'inconnu'
            ));
        }
    }
}
