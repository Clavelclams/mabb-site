<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\OperationTresorerie;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des justificatifs (tickets, factures) pour les opérations
 * de trésorerie — Bureau Phase D.1.
 *
 * VALIDATION STRICTE :
 *   - MIME whitelist (PDF + JPG/PNG/WebP/HEIC pour les tickets caisse)
 *   - Taille max 5 Mo (un ticket photo HD pèse rarement plus)
 *   - Nom de fichier généré (uniqid) → anti path traversal + collisions
 *
 * STOCKAGE : public/uploads/tresorerie/{clubId}/operations/{uniqid.ext}
 * Sous-dossier par club pour multi-tenant strict côté fichiers + faciliter
 * un éventuel export "tous mes justificatifs" pour un trésorier.
 *
 * HEIC NOTE :
 *   - Format iPhone natif. Stocké tel quel, pas converti.
 *   - Safari iOS l'affichera. Chrome desktop NON sans extension.
 *   - On préviendra l'user en UI : "Pour partager au comptable, préférez PDF/JPG".
 *
 * SÉCURITÉ : ce service NE vérifie PAS les permissions — c'est au contrôleur
 * d'appeler TresorerieVoter::CAN_MANAGE avant d'invoquer le service.
 */
final class JustificatifOperationUploader
{
    /** MIME types autorisés (whitelist) — factures + tickets photo */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif', // variante HEIC
    ];

    /** Taille max : 5 Mo (largement pour ticket photo ou PDF facture) */
    private const MAX_SIZE = 5 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/tresorerie/ — injecté via services.yaml */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload un justificatif et met à jour l'entité OperationTresorerie.
     *
     * Pourquoi muter l'entité ici plutôt que retourner un DTO ?
     *   - Le justificatif n'a pas d'existence indépendante de l'opération.
     *   - Garde le code controller simple : juste upload($file, $op).
     *
     * @throws \InvalidArgumentException Si validation échoue (MIME, taille)
     * @throws FileException Si le déplacement physique échoue
     */
    public function upload(UploadedFile $file, OperationTresorerie $operation): void
    {
        $this->validate($file);

        // ⚠️ Capture AVANT $file->move() : après move(), le fichier temporaire
        // n'existe plus → getSize() plante avec "stat failed for ...tmp".
        // Bug Symfony connu, reproduit déjà sur ReunionDocumentUploader.
        $mime        = $file->getMimeType() ?? '';
        $taille      = (int) $file->getSize();
        $nomOriginal = $file->getClientOriginalName();
        $extension   = self::ALLOWED_MIME_TYPES[$mime];

        $clubId = $operation->getClub()?->getId();
        if ($clubId === null) {
            throw new \LogicException('L\'opération doit avoir un club assigné avant upload du justificatif.');
        }

        // Sous-dossier par club + sous-dossier "operations" pour isoler
        // des éventuels futurs justificatifs notes de frais (D.2 → "notes_frais/")
        $targetDir = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'operations';

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $filename = sprintf('%s.%s', uniqid('', false), $extension);
        $file->move($targetDir, $filename);

        $operation->setJustificatifPath($filename);
        $operation->setJustificatifNomOriginal($nomOriginal);
        $operation->setJustificatifMimeType($mime);
        $operation->setJustificatifTaille($taille);
    }

    /**
     * Supprime physiquement le fichier d'un justificatif.
     * Le caller doit ensuite reset les champs côté entité (ou la supprimer).
     */
    public function delete(OperationTresorerie $operation): void
    {
        $path = $operation->getJustificatifPath();
        if ($path === null) {
            return;
        }

        // Anti path traversal
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return;
        }

        $clubId = $operation->getClub()?->getId();
        if ($clubId === null) {
            return;
        }

        $fullPath = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'operations'
            . DIRECTORY_SEPARATOR . $path;

        if (file_exists($fullPath) && is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Renvoie le chemin absolu du justificatif (pour le servir en streaming).
     * Retourne null si pas de justificatif ou si le fichier physique a disparu.
     */
    public function getAbsolutePath(OperationTresorerie $operation): ?string
    {
        $path = $operation->getJustificatifPath();
        $clubId = $operation->getClub()?->getId();

        if ($path === null || $clubId === null) {
            return null;
        }

        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }

        $fullPath = $this->uploadBaseDirectory
            . DIRECTORY_SEPARATOR . $clubId
            . DIRECTORY_SEPARATOR . 'operations'
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
