<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\InscriptionSortie;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload des DÉCHARGES / autorisations parentales signées d'une sortie
 * (Sorties Lot D v2 — doc 23 §8, RGPD-0010).
 *
 * ⚠️ DIFFÉRENCE MAJEURE avec DocumentUploader (ENT) : stockage HORS public/
 * (var/decharges/) — une décharge contient l'identité d'une MINEURE et la
 * signature d'un parent ; elle ne doit JAMAIS être servie par le serveur web
 * en accès direct. La lecture passe obligatoirement par le contrôleur
 * (BinaryFileResponse) derrière le Voter CLUB_STAFF.
 *
 * VALIDATION : PDF + images (photo du papier signé), 10 Mo max.
 * STOCKAGE : var/decharges/{clubId}/{uniqid}.{ext} — nom généré (anti
 * path-traversal + anti-collision), sous-dossier par club (multi-tenant).
 *
 * SÉCURITÉ : ce service NE vérifie PAS les permissions — le contrôleur doit
 * appeler ClubVoter::CLUB_STAFF avant de l'invoquer (même contrat que les
 * autres uploaders du projet).
 */
final class DechargeSortieUploader
{
    /** MIME autorisés : PDF ou photo/scan de l'autorisation signée. */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];

    /** Taille max : 10 Mo (photo de téléphone incluse). */
    private const MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers var/decharges/ — injecté en services.yaml */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload la décharge d'une inscription et renvoie le nom de fichier à
     * stocker dans `autorisationFichier`. Supprime l'ancienne décharge si
     * l'inscription en avait déjà une (remplacement).
     *
     * @throws \InvalidArgumentException Si validation échoue (MIME, taille)
     * @throws FileException             Si le déplacement physique échoue
     */
    public function upload(UploadedFile $file, InscriptionSortie $inscription): string
    {
        $this->validate($file);

        $clubId = $inscription->getClub()?->getId();
        if ($clubId === null) {
            throw new \InvalidArgumentException('Inscription sans club (événement manquant ?).');
        }

        // Remplacement : purger l'ancien fichier avant d'écrire le nouveau.
        $this->delete($inscription);

        $mime      = $file->getMimeType() ?? '';
        $extension = self::ALLOWED_MIME_TYPES[$mime];

        $clubDir = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $clubId;
        if (!is_dir($clubDir)) {
            mkdir($clubDir, 0750, true);
        }

        $filename = sprintf('%s.%s', uniqid('decharge_', false), $extension);
        $file->move($clubDir, $filename);

        return $filename;
    }

    /**
     * Supprime physiquement la décharge d'une inscription (si elle existe).
     * Ne modifie PAS l'entité — au caller de setAutorisationFichier(null) + flush.
     */
    public function delete(InscriptionSortie $inscription): void
    {
        $fullPath = $this->getAbsolutePath($inscription);
        if ($fullPath !== null) {
            @unlink($fullPath);
        }
    }

    /**
     * Chemin absolu de la décharge (pour BinaryFileResponse), ou null si
     * absente / invalide.
     */
    public function getAbsolutePath(InscriptionSortie $inscription): ?string
    {
        $path   = $inscription->getAutorisationFichier();
        $clubId = $inscription->getClub()?->getId();

        if ($path === null || $clubId === null) {
            return null;
        }

        // Anti path traversal : le nom stocké est plat, généré par nous.
        if (str_contains($path, '/') || str_contains($path, '\\') || str_contains($path, '..')) {
            return null;
        }

        $fullPath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $clubId . DIRECTORY_SEPARATOR . $path;

        return file_exists($fullPath) && is_file($fullPath) ? $fullPath : null;
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
                'Format non autorisé (%s). Formats acceptés : PDF, JPG, PNG, WEBP.',
                $mime ?? 'inconnu'
            ));
        }
    }
}
