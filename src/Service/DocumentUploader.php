<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Sport\Document;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des documents ENT.
 *
 * VALIDATION :
 *   - MIME whitelist (PDF, Word, Excel, PowerPoint, ODT, images, TXT)
 *   - Taille max 20 Mo (documents club pouvant être plus lourds que des PDFs simples)
 *   - Nom de fichier généré (uniqid) → anti path traversal + collisions
 *
 * STOCKAGE : public/uploads/ent/{clubId}/ — sous-dossier par club
 *
 * SÉCURITÉ : ce service NE vérifie PAS les permissions — le contrôleur
 * doit appeler ClubVoter::CLUB_STAFF_ELARGI avant d'invoquer le service.
 */
final class DocumentUploader
{
    /** MIME types autorisés (whitelist bureautique complète) */
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
        'image/gif'                                                                        => 'gif',
        'text/plain'                                                                       => 'txt',
    ];

    /** Taille max : 20 Mo */
    private const MAX_SIZE = 20 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/ent/ — injecté en services.yaml */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload un fichier pour l'ENT d'un club.
     *
     * @return Document L'entité créée (non flushée — au caller de persist + flush)
     * @throws \InvalidArgumentException Si validation échoue (MIME, taille)
     * @throws FileException Si le déplacement physique échoue
     */
    public function upload(UploadedFile $file, Club $club): Document
    {
        $this->validate($file);

        // ⚠️ Capturer AVANT $file->move() : après move(), le fichier temporaire n'existe
        // plus → getSize() plante avec "stat failed for ...tmp". Bug Symfony connu.
        $mime        = $file->getMimeType() ?? '';
        $taille      = (int) $file->getSize();
        $nomOriginal = $file->getClientOriginalName();
        $extension   = self::ALLOWED_MIME_TYPES[$mime];

        // Sous-dossier par club
        $clubDir = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $club->getId();
        if (!is_dir($clubDir)) {
            mkdir($clubDir, 0755, true);
        }

        // Nom sûr et imprévisible : uniqid + extension
        $filename = sprintf('%s.%s', uniqid('ent_', false), $extension);
        $file->move($clubDir, $filename);

        $doc = new Document();
        $doc->setClub($club);
        $doc->setNomOriginal($nomOriginal);
        $doc->setPath($filename);
        $doc->setMimeType($mime);
        $doc->setTaille($taille);

        return $doc;
    }

    /**
     * Supprime physiquement le fichier d'un document.
     * Le caller doit ensuite remove() l'entité et flush().
     */
    public function delete(Document $doc): void
    {
        $path    = $doc->getPath();
        $clubId  = $doc->getClub()?->getId();

        if ($path === null || $clubId === null) return;

        // Anti path traversal
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return;
        }

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $clubId . DIRECTORY_SEPARATOR . $path;
        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Renvoie le chemin absolu d'un document (pour BinaryFileResponse).
     */
    public function getAbsolutePath(Document $doc): ?string
    {
        $path   = $doc->getPath();
        $clubId = $doc->getClub()?->getId();

        if ($path === null || $clubId === null) return null;

        // Anti path traversal
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $clubId . DIRECTORY_SEPARATOR . $path;
        return file_exists($fullPath) ? $fullPath : null;
    }

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Fichier trop volumineux (%.1f Mo). Maximum autorisé : 20 Mo.',
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
