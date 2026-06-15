<?php

declare(strict_types=1);

namespace App\Service\Ffbb;

use Google\Cloud\Vision\V1\AnnotateFileRequest;
use Google\Cloud\Vision\V1\BatchAnnotateFilesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\InputConfig;
use Psr\Log\LoggerInterface;

/**
 * [B-FFBB-OCR 15/06/2026] Wrapper autour de Google Cloud Vision API.
 *
 * Pourquoi un service dédié :
 *   - Isole la dépendance Google\Cloud\Vision pour faciliter les tests
 *   - Centralise la config (chemin clé, options API)
 *   - Permet de swapper l'implémentation OCR (Tesseract, Adobe…) sans toucher au parser
 *
 * Coût : ~0,0015 $ par PDF (Document Text Detection).
 * Free tier : 1000 unités/mois gratuites → on est tranquille (~75/saison).
 *
 * USAGE :
 *   $text = $ocrService->extractTextFromPdf('/path/to/resume.pdf');
 *   // $text contient le texte OCR brut sur plusieurs pages
 *
 * STRATÉGIE en cas d'échec :
 *   - Si API timeout/quota dépassé → on log et on remonte l'exception
 *   - Le caller (parser ou command) décide quoi faire (retry / fallback / skip)
 */
class GoogleVisionOcrService
{
    public function __construct(
        private readonly string $googleVisionKeyPath,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Extrait le texte d'un PDF via Vision Document Text Detection.
     *
     * @param string $pdfPath Chemin absolu vers le fichier PDF
     * @return string Texte concaténé de toutes les pages (séparées par \n\n--- PAGE X ---\n\n)
     * @throws \RuntimeException si l'extraction échoue
     */
    public function extractTextFromPdf(string $pdfPath): string
    {
        if (!file_exists($pdfPath)) {
            throw new \RuntimeException("PDF introuvable : $pdfPath");
        }
        if (!file_exists($this->googleVisionKeyPath)) {
            throw new \RuntimeException(
                "Clé Google Vision introuvable : {$this->googleVisionKeyPath}. "
                . "Vérifie le chemin dans services.yaml ou .env.local."
            );
        }

        $pdfContent = file_get_contents($pdfPath);
        $pdfSizeKb = round(strlen($pdfContent) / 1024, 1);

        $this->logger->info('Google Vision OCR : début extraction', [
            'pdf' => basename($pdfPath),
            'size_kb' => $pdfSizeKb,
        ]);

        $client = new ImageAnnotatorClient([
            'credentials' => $this->googleVisionKeyPath,
        ]);

        try {
            $inputConfig = (new InputConfig())
                ->setContent($pdfContent)
                ->setMimeType('application/pdf');

            $feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);

            $annotateRequest = (new AnnotateFileRequest())
                ->setInputConfig($inputConfig)
                ->setFeatures([$feature]);

            $batchRequest = (new BatchAnnotateFilesRequest())
                ->setRequests([$annotateRequest]);

            $start = microtime(true);
            $response = $client->batchAnnotateFiles($batchRequest);
            $elapsedMs = round((microtime(true) - $start) * 1000);

            $allText = '';
            $pageCount = 0;

            foreach ($response->getResponses() as $fileResponse) {
                foreach ($fileResponse->getResponses() as $i => $pageResponse) {
                    $pageNum = $i + 1;
                    $pageCount++;
                    $fullText = $pageResponse->getFullTextAnnotation();
                    if ($fullText === null) {
                        continue;
                    }
                    $allText .= "\n\n--- PAGE {$pageNum} ---\n\n";
                    $allText .= $fullText->getText();
                }
            }

            $this->logger->info('Google Vision OCR : OK', [
                'pdf' => basename($pdfPath),
                'pages' => $pageCount,
                'chars' => strlen($allText),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $allText;
        } catch (\Throwable $e) {
            $this->logger->error('Google Vision OCR : échec', [
                'pdf' => basename($pdfPath),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            throw new \RuntimeException(
                "Échec OCR Vision sur " . basename($pdfPath) . " : " . $e->getMessage(),
                0,
                $e,
            );
        } finally {
            $client->close();
        }
    }
}
