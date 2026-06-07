<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionDocument;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des documents attachés aux réunions.
 *
 * VALIDATION STRICTE :
 *   - MIME whitelist (PDF, Word, Excel, PowerPoint, images, ODT)
 *   - Taille max 10 Mo
 *   - Nom de fichier généré (uniqid) → anti path traversal + collisions
 *
 * STOCKAGE : public/uploads/reunions/{reunionId}/ — sous-dossier par réunion
 * pour faciliter le nettoyage si la réunion est supprimée (CASCADE BDD + dossier).
 *
 * SÉCURITÉ : le service NE vérifie PAS les permissions — c'est au contrôleur
 * d'appeler ClubVoter::CLUB_STAFF avant d'invoquer le service.
 */
final class ReunionDocumentUploader
{
    /** MIME types autorisés (whitelist) — couvre les formats bureautiques courants */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf'                                                                  => 'pdf',
        'application/msword'                                                              => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'          => 'docx',
        'application/vnd.ms-excel'                                                        => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'                => 'xlsx',
        'application/vnd.ms-powerpoint'                                                   => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'       => 'pptx',
        'application/vnd.oasis.opendocument.text'                                         => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet'                                  => 'ods',
        'image/jpeg'                                                                       => 'jpg',
        'image/png'                                                                        => 'png',
        'image/webp'                                                                       => 'webp',
        'text/plain'                                                                       => 'txt',
    ];

    /** Taille max : 10 Mo (largement pour un PDF moyen) */
    private const MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/reunions/ — injecté en services.yaml */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload un fichier pour une réunion.
     *
     * @return ReunionDocument L'entité créée (non flushée — au caller de persist + flush)
     * @throws \InvalidArgumentException Si validation échoue (MIME, taille)
     * @throws FileException Si le déplacement physique échoue
     */
    public function upload(UploadedFile $file, Reunion $reunion): ReunionDocument
    {
        $this->validate($file);

        // ⚠️ Capturer AVANT $file->move() : après move(), le fichier temporaire n'existe
        // plus → getSize() plante avec "stat failed for ...tmp". Bug Symfony connu.
        $mime         = $file->getMimeType() ?? '';
        $taille       = (int) $file->getSize();
        $nomOriginal  = $file->getClientOriginalName();
        $extension    = self::ALLOWED_MIME_TYPES[$mime];

        // Sous-dossier par réunion (créé à la volée)
        $reunionDir = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $reunion->getId();
        if (!is_dir($reunionDir)) {
            mkdir($reunionDir, 0755, true);
        }

        // Nom sûr et imprévisible : uniqid + extension
        $filename = sprintf('%s.%s', uniqid('', false), $extension);
        $file->move($reunionDir, $filename);

        $doc = new ReunionDocument();
        $doc->setReunion($reunion);
        $doc->setNomOriginal($nomOriginal);
        $doc->setPath($filename);
        $doc->setMimeType($mime);
        $doc->setTaille($taille);

        return $doc;
    }

    /**
     * Supprime physiquement le fichier d'un document.
     * Le caller doit ensuite remove() l'entité.
     */
    public function delete(ReunionDocument $doc): void
    {
        $path = $doc->getPath();
        if ($path === null) return;

        // Anti path traversal
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return;
        }

        $reunionId = $doc->getReunion()?->getId();
        if ($reunionId === null) return;

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $reunionId . DIRECTORY_SEPARATOR . $path;
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Renvoie le chemin absolu d'un document (pour servir).
     */
    public function getAbsolutePath(ReunionDocument $doc): ?string
    {
        $path = $doc->getPath();
        $reunionId = $doc->getReunion()?->getId();
        if ($path === null || $reunionId === null) return null;

        // Anti path traversal
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $reunionId . DIRECTORY_SEPARATOR . $path;
        return file_exists($fullPath) ? $fullPath : null;
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Fichier trop volumineux (%.1f Mo). Maximum autorisé : 10 Mo.',
                $file->getSize() / 1024 / 1024
            ));
        }

        $mime = $file->getMimeType();
        if (!array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException(sprintf(
                'Format non autorisé (%s). Formats acceptés : PDF, Word, Excel, PowerPoint, ODT, JPG, PNG, TXT.',
                $mime ?? 'inconnu'
            ));
        }
    }
}
