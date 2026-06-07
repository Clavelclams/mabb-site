<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\NoteFrais;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des justificatifs pour les NOTES DE FRAIS — Bureau D.2.
 *
 * Pourquoi un service séparé de JustificatifOperationUploader ?
 *   - Les chemins sont différents (notes_frais/ vs operations/)
 *   - Le justificatif d'une note de frais est OBLIGATOIRE (pas optionnel),
 *     donc la validation peut différer un jour (taille min ?)
 *   - SRP : si un jour on veut un traitement spécifique (anti-doublon, OCR…)
 *     pour les notes de frais, on l'ajoute ici sans toucher l'autre service
 *
 * MIME types & taille : alignés sur l'opération (PDF + JPG/PNG/WebP/HEIC, 5 Mo).
 * Stockage : public/uploads/tresorerie/{clubId}/notes_frais/{uniqid.ext}
 */
final class JustificatifNoteFraisUploader
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif',
    ];

    private const MAX_SIZE = 5 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/tresorerie/ — partagé avec opérations */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload le justificatif et met à jour les champs de la note.
     *
     * @throws \InvalidArgumentException Si validation échoue
     * @throws FileException Si déplacement physique échoue
     * @throws \LogicException Si la note n'a pas de club assigné
     */
    public function upload(UploadedFile $file, NoteFrais $note): void
    {
        $this->validate($file);

        // Capture AVANT move() — bug Symfony connu
        $mime        = $file->getMimeType() ?? '';
        $taille      = (int) $file->getSize();
        $nomOriginal = $file->getClientOriginalName();
        $extension   = self::ALLOWED_MIME_TYPES[$mime];

        $clubId = $note->getClub()?->getId();
        if ($clubId === null) {
            throw new \LogicException('La note de frais doit avoir un club assigné avant upload.');
        }

        $targetDir = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'notes_frais';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = sprintf('%s.%s', uniqid('', false), $extension);
        $file->move($targetDir, $filename);

        $note->setJustificatifPath($filename);
        $note->setJustificatifNomOriginal($nomOriginal);
        $note->setJustificatifMimeType($mime);
        $note->setJustificatifTaille($taille);
    }

    /**
     * Supprime le fichier physique d'un justificatif.
     * IMPORTANT : on ne devrait JAMAIS supprimer le justificatif d'une note
     * VALIDEE (justificatif compta à garder), mais le service ne le sait pas —
     * c'est au controller de vérifier le statut avant d'appeler.
     */
    public function delete(NoteFrais $note): void
    {
        $path = $note->getJustificatifPath();
        if ($path === '') {
            return;
        }

        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return;
        }

        $clubId = $note->getClub()?->getId();
        if ($clubId === null) {
            return;
        }

        $fullPath = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'notes_frais'
            . DIRECTORY_SEPARATOR . $path;

        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    public function getAbsolutePath(NoteFrais $note): ?string
    {
        $path = $note->getJustificatifPath();
        $clubId = $note->getClub()?->getId();

        if ($path === '' || $clubId === null) {
            return null;
        }

        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }

        $fullPath = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'notes_frais'
            . DIRECTORY_SEPARATOR . $path;

        return file_exists($fullPath) ? $fullPath : null;
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Justificatif trop volumineux (%.1f Mo). Maximum : 5 Mo.',
                $file->getSize() / 1024 / 1024
            ));
        }

        $mime = $file->getMimeType();
        if (!array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException(sprintf(
                'Format non autorisé (%s). Acceptés : PDF, JPG, PNG, WebP, HEIC.',
                $mime ?? 'inconnu'
            ));
        }
    }
}
