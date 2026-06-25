<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Sport\ContenuSeance;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Gestion des fichiers joints d'une fiche séance (ContenuSeance).
 *
 * TYPES SUPPORTÉS :
 *   - Images (JPEG, PNG, WEBP, GIF) → type = 'image'
 *   - PDFs (schémas, exercices)     → type = 'pdf'
 *
 * STOCKAGE :
 *   public/uploads/seances/{clubId}/{uniqid}.{ext}
 *   → sous-dossier par club pour multi-tenant + faciliter la purge RGPD.
 *
 * JSON STRUCTURE (stockée dans ContenuSeance.fichiers) :
 *   [
 *     { "type": "pdf",   "path": "uploads/seances/5/abc123.pdf",  "originalName": "exercice.pdf", "size": 204800 },
 *     { "type": "image", "path": "uploads/seances/5/xyz789.jpg",  "originalName": "schema.jpg",   "size": 98304  },
 *   ]
 *
 * RÈGLES :
 *   - Max 5 fichiers par ContenuSeance (contrôle au niveau du controller)
 *   - Max 5 Mo par fichier (contrôle ici + Constraint dans ContenuSeanceType)
 *   - Noms de fichiers randomisés (uniqid) → pas de prédiction de chemin
 *   - Anti path-traversal sur la suppression
 */
final class ContenuSeanceMediaUploader
{
    private const MIME_TO_EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];

    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 Mo

    public function __construct(
        /** Chemin absolu vers public/uploads/seances (cf services.yaml) */
        private readonly string $uploadBaseDirectory,
    ) {}

    /**
     * Upload un seul fichier et retourne son entrée JSON (array).
     *
     * @return array{type: string, path: string, originalName: string, size: int}
     * @throws \InvalidArgumentException Si MIME ou taille invalide
     * @throws FileException             Si déplacement physique échoue
     */
    public function uploadFichier(UploadedFile $file, int $clubId): array
    {
        $this->validate($file);

        // Capture AVANT move() — le fichier devient indisponible après
        $mime         = $file->getMimeType() ?? '';
        $ext          = self::MIME_TO_EXT[$mime];
        $type         = str_starts_with($mime, 'image/') ? 'image' : 'pdf';
        $originalName = $file->getClientOriginalName();
        $size         = $file->getSize();

        // Dossier par club — multi-tenant
        $dir = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $clubId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = uniqid('cs_', true) . '.' . $ext;
        $file->move($dir, $filename);

        return [
            'type'         => $type,
            'path'         => 'uploads/seances/' . $clubId . '/' . $filename,
            'originalName' => $originalName,
            'size'         => $size,
        ];
    }

    /**
     * Upload plusieurs fichiers et les ajoute à un ContenuSeance existant.
     *
     * @param  UploadedFile[]  $files
     * @param  ContenuSeance   $contenu  L'entité doit déjà avoir son club défini
     * @return int             Nombre de fichiers uploadés
     */
    public function uploadPourContenu(array $files, ContenuSeance $contenu): int
    {
        $clubId = $contenu->getClub()->getId()
            ?? throw new \LogicException('Club non persisté — ID absent.');

        $count = 0;
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            $meta = $this->uploadFichier($file, $clubId);
            $contenu->addFichier($meta['type'], $meta['path'], $meta['originalName'], $meta['size']);
            $count++;
        }
        return $count;
    }

    /**
     * Supprime physiquement un fichier référencé dans le JSON.
     *
     * @param  array{path: string, ...}  $fichierMeta  Entrée JSON du fichier à supprimer
     */
    public function supprimerFichier(array $fichierMeta): void
    {
        $path = $fichierMeta['path'] ?? '';
        if ($path === '') {
            return;
        }

        // Anti path-traversal : le path ne doit pas sortir de uploads/seances/
        if (!str_starts_with($path, 'uploads/seances/') || str_contains($path, '..')) {
            return; // refus silencieux
        }

        // Reconstruit le chemin absolu depuis la racine uploads/seances/
        // uploadBaseDirectory = <proj>/public/uploads/seances
        // path stocké         = uploads/seances/{clubId}/{file}.ext
        // → partie relative   = {clubId}/{file}.ext
        $relativePart = substr($path, strlen('uploads/seances/'));
        $absolutePath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $relativePart;

        if (file_exists($absolutePath) && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Supprime tous les fichiers physiques d'un ContenuSeance.
     * À appeler juste avant de supprimer l'entité.
     */
    public function supprimerTousFichiers(ContenuSeance $contenu): void
    {
        foreach ($contenu->getFichiers() as $meta) {
            $this->supprimerFichier($meta);
        }
    }

    /**
     * Retourne le chemin ABSOLU d'un fichier stocké.
     * Retourne null si le fichier n'existe pas sur le disque.
     */
    public function getAbsolutePath(array $fichierMeta): ?string
    {
        $path = $fichierMeta['path'] ?? '';
        if ($path === '' || !str_starts_with($path, 'uploads/seances/') || str_contains($path, '..')) {
            return null;
        }

        $relativePart = substr($path, strlen('uploads/seances/'));
        $absolutePath = $this->uploadBaseDirectory . DIRECTORY_SEPARATOR . $relativePart;

        return file_exists($absolutePath) ? $absolutePath : null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function validate(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Fichier trop volumineux (%.1f Mo). Maximum autorisé : 5 Mo.',
                $file->getSize() / 1024 / 1024
            ));
        }

        $mime = $file->getMimeType();
        if (!array_key_exists($mime, self::MIME_TO_EXT)) {
            throw new \InvalidArgumentException(sprintf(
                'Format non autorisé (%s). Acceptés : JPG, PNG, WEBP, GIF, PDF.',
                $mime ?? 'inconnu'
            ));
        }
    }
}
