<?php

declare(strict_types=1);

/**
 * [B-FFBB-OCR 15/06/2026] Script de test rapide Google Vision API sur un PDF FFBB.
 *
 * Usage :
 *   php bin/test-vision.php <chemin/vers/resume.pdf>
 *
 * Affiche le texte OCR brut extrait par Vision pour qu'on observe le format
 * AVANT d'écrire la regex de parsing FfbbResumeOcrParser.
 *
 * Coût : ~0,0015 $ par PDF (1 seul appel API).
 *
 * NOTE : utilise la v2 de google/cloud-vision (namespaces \Client\ et
 * BatchAnnotateFilesRequest object au lieu d'array v1).
 */

require __DIR__ . '/../vendor/autoload.php';

use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\InputConfig;
use Google\Cloud\Vision\V1\AnnotateFileRequest;
use Google\Cloud\Vision\V1\BatchAnnotateFilesRequest;

$pdfPath = $argv[1] ?? null;

if ($pdfPath === null || !file_exists($pdfPath)) {
    fwrite(STDERR, "❌ Usage: php bin/test-vision.php <chemin/vers/resume.pdf>\n");
    fwrite(STDERR, "   Le fichier doit exister.\n");
    exit(1);
}

$keyPath = __DIR__ . '/../config/credentials/google-vision-key.json';
if (!file_exists($keyPath)) {
    fwrite(STDERR, "❌ Clé Google Vision introuvable : $keyPath\n");
    fwrite(STDERR, "   Vérifie que tu as bien déplacé la clé dans config/credentials/\n");
    exit(1);
}

echo "📄 PDF : $pdfPath\n";
echo "🔑 Clé : $keyPath\n";
echo "⏳ Appel Google Vision API...\n\n";

$client = new ImageAnnotatorClient([
    'credentials' => $keyPath,
]);

$pdfContent = file_get_contents($pdfPath);
$pdfSize = strlen($pdfContent);
echo "Taille PDF : " . round($pdfSize / 1024, 1) . " Ko\n\n";

// === v2 API : on construit des objets Request typés ===
$inputConfig = (new InputConfig())
    ->setContent($pdfContent)
    ->setMimeType('application/pdf');

$feature = (new Feature())->setType(Type::DOCUMENT_TEXT_DETECTION);

$annotateFileRequest = (new AnnotateFileRequest())
    ->setInputConfig($inputConfig)
    ->setFeatures([$feature]);

$batchRequest = (new BatchAnnotateFilesRequest())
    ->setRequests([$annotateFileRequest]);

try {
    $startMs = microtime(true);
    $response = $client->batchAnnotateFiles($batchRequest);
    $elapsed = round((microtime(true) - $startMs) * 1000);
} catch (\Throwable $e) {
    fwrite(STDERR, "❌ Erreur API : " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack : " . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    $client->close();
}

echo "✓ API a répondu en {$elapsed} ms\n\n";

// === Affichage du résultat ===
foreach ($response->getResponses() as $fileResponse) {
    $pages = $fileResponse->getResponses();
    echo "✅ Vision a traité " . count($pages) . " page(s) du PDF\n";
    echo str_repeat("=", 70) . "\n";

    foreach ($pages as $i => $pageResponse) {
        $pageNum = $i + 1;
        echo "\n========================== PAGE $pageNum ==========================\n";
        $fullText = $pageResponse->getFullTextAnnotation();
        if ($fullText === null) {
            echo "(pas de texte détecté sur cette page)\n";
            continue;
        }
        echo $fullText->getText();
        echo "\n";

        $textChars = strlen($fullText->getText());
        echo "\n--- STATS PAGE $pageNum ---\n";
        echo "Caractères extraits : $textChars\n";
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "✓ Test terminé.\n";
echo "Coût estimé : ~0,0015 \$ (1 appel API Document Text Detection)\n";
