<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\Rencontre;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service d'upload des PDFs FFBB officiels d'une rencontre.
 *
 * 3 TYPES SUPPORTÉS :
 *   - 'resume'    : stats individuelles agrégées
 *   - 'feuille'   : feuille de match (table de marque officielle)
 *   - 'positions' : shot chart / positions des tirs
 *
 * RESPONSABILITÉS :
 *   - Valider le fichier (MIME PDF strict, taille max 5MB)
 *   - Générer un nom de fichier sécurisé (anti path traversal)
 *   - Déplacer vers public/uploads/rencontres/
 *   - Supprimer l'ancien si remplacement (pas de fichiers orphelins)
 *
 * SÉCURITÉ FORTE :
 *   - MIME stricte 'application/pdf' (pas juste l'extension, qui peut mentir)
 *   - Filename = 'rencontre_{id}_{type}_{uniqid}.pdf' → impossible de prédire
 *     le nom complet depuis l'extérieur (un attaquant ne peut pas accéder
 *     directement au fichier sans connaître le uniqid)
 *   - Validation du type via whitelist (Rencontre::setPdfPath() throw si invalide)
 *
 * MULTI-TENANT :
 *   Le service ne vérifie PAS le club — c'est le contrôleur (ClubVoter) qui le fait.
 *   Le service ne fait que de la mécanique d'upload.
 */
final class RencontrePdfUploader
{
    /** Types de PDFs valides — whitelist stricte */
    public const TYPES_VALIDES = ['resume', 'feuille', 'positions'];

    /** Taille max : 5 Mo (largement suffisant pour un PDF FFBB) */
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        /** Chemin absolu vers public/uploads/rencontres/ — injecté via services.yaml */
        private readonly string $uploadDirectory,
    ) {}

    /**
     * Upload un PDF pour une rencontre.
     *
     * @param string $type Doit être dans TYPES_VALIDES
     * @return string Le nom du fichier stocké (à persister via Rencontre::setPdfPath())
     * @throws \InvalidArgumentException si validation échoue
     * @throws FileException si le déplacement physique échoue
     */
    public function upload(UploadedFile $file, Rencontre $rencontre, string $type): string
    {
        if (!in_array($type, self::TYPES_VALIDES, true)) {
            throw new \InvalidArgumentException("Type de PDF invalide : $type. Attendus : " . implode(', ', self::TYPES_VALIDES));
        }

        $this->validate($file);

        // Supprime l'ancien fichier si déjà présent (évite les orphelins)
        $ancienPath = $rencontre->getPdfPath($type);
        if ($ancienPath !== null) {
            $this->deletePhysicalFile($ancienPath);
        }

        // Génère un nom sûr et imprévisible
        // Format : rencontre_42_resume_64b3f7a1b2c8d.pdf
        $filename = sprintf(
            'rencontre_%d_%s_%s.pdf',
            $rencontre->getId() ?? 0,
            $type,
            uniqid('', false)
        );

        // Déplacement physique. Symfony fait du sanitization sur le nom.
        $file->move($this->uploadDirectory, $filename);

        return $filename;
    }

    /**
     * Supprime un PDF d'une rencontre (fichier physique uniquement).
     * Le caller doit ensuite mettre le path à null sur l'entité.
     */
    public function delete(Rencontre $rencontre, string $type): void
    {
        $path = $rencontre->getPdfPath($type);
        if ($path === null) {
            return;
        }
        $this->deletePhysicalFile($path);
    }

    /**
     * Retourne le chemin ABSOLU d'un PDF (pour servir le fichier ou le streamer).
     * Renvoie null si pas de fichier ou type invalide.
     */
    public function getAbsolutePath(Rencontre $rencontre, string $type): ?string
    {
        $filename = $rencontre->getPdfPath($type);
        if ($filename === null) {
            return null;
        }
        $path = $this->uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        return file_exists($path) ? $path : null;
    }

    /**
     * Validation MIME + taille.
     * Lève une exception explicative en cas d'échec (catchée par le contrôleur).
     */
    private function validate(UploadedFile $file): void
    {
        // Taille — protection DoS + cohérence FFBB
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Fichier trop volumineux (%.1f Mo). Maximum autorisé : 5 Mo.',
                $file->getSize() / 1024 / 1024
            ));
        }

        // MIME stricte : inspection du contenu réel, pas juste l'extension
        // getMimeType() utilise les magic bytes du fichier (fileinfo)
        $mime = $file->getMimeType();
        if ($mime !== 'application/pdf') {
            throw new \InvalidArgumentException(sprintf(
                'Format invalide (%s). Seuls les fichiers PDF sont acceptés.',
                $mime ?? 'inconnu'
            ));
        }
    }

    /**
     * Supprime un fichier du dossier d'upload — silencieux si absent.
     * Anti path-traversal : on refuse tout filename qui contient / \ ou ..
     */
    private function deletePhysicalFile(string $filename): void
    {
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return; // refus silencieux d'un filename suspect
        }
        $filePath = $this->uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }
}
