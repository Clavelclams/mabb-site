<?php

declare(strict_types=1);

namespace App\Service\Ffbb;

use App\Entity\Sport\Rencontre;
use App\Entity\Sport\TirFfbb;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\TirFfbbRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * [B22c 12/06/2026] Parser du PDF positiontir_*.pdf FFBB.
 *
 * ============================================================================
 * VERSION V1 (12/06/2026) :
 *   - Extraction TEXTE uniquement (nom des joueuses + nombre de tirs marqués)
 *   - Pas d'extraction des coordonnées graphiques X/Y des croix "X"
 *   - Crée 1 ligne TirFfbb par tir avec position NULL
 *
 * VERSION V2 (futur — quand on en aura besoin) :
 *   - Conversion PDF → image (poppler/imagick)
 *   - Détection des "X" via comparaison pixels (chaque mini-terrain isolé)
 *   - Mapping coordonnées pixel → pourcentage 0-100 du terrain
 *   - Stockage position_x / position_y dans TirFfbb
 *
 * Le PDF positiontir contient typiquement :
 *   - Page 1 : terrain global équipe A (tous les tirs marqués)
 *   - Page 2 : terrain global équipe B
 *   - Pages 3+ : mini-terrains par joueuse (1 ou 2 par page) avec les "X"
 *
 * V1 stratégie : on parse le texte pour identifier "X tir réussi pour J. LEFEVRE",
 * on compte, on crée N entrées TirFfbb sans position.
 * ============================================================================
 */
class FfbbPositionTirParser
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TirFfbbRepository $tirRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    /**
     * Parse le PDF positiontir et persiste les TirFfbb (sans coordonnées en V1).
     * Retourne le nombre de tirs extraits.
     */
    public function parseEtPersister(Rencontre $rencontre): int
    {
        $relativePath = $rencontre->getPositionsTirsPath();
        if ($relativePath === null) {
            return 0;
        }

        $absolutePath = rtrim($this->projectDir, '/') . '/public/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            $this->logger->warning('Positiontir PDF introuvable', ['path' => $absolutePath]);
            return 0;
        }

        // Idempotent : supprime les anciens TirFfbb (source=ffbb) pour cette rencontre
        foreach ($this->tirRepo->findForRencontre($rencontre, TirFfbb::SOURCE_FFBB) as $old) {
            $this->em->remove($old);
        }
        $this->em->flush();

        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            $texte = $pdf->getText();
        } catch (\Throwable $e) {
            $this->logger->error('Échec lecture PDF positiontir', ['error' => $e->getMessage()]);
            return 0;
        }

        // Extraction texte : on cherche les paterns "NOM Prénom" + nombre de tirs
        // Format observé : "MILAPIE R. - 4 tirs marqués" ou "LEFEVRE Jody (10) - 6 réussis"
        $tirs = $this->extraireTirsFromText($texte);
        $joueursDuClub = $this->joueurRepo->findBy(['club' => $rencontre->getClub(), 'isActive' => true]);

        $count = 0;
        foreach ($tirs as $tir) {
            $joueurMatch = $this->matchJoueurParNom($tir['nom'], $joueursDuClub);

            // Crée N entrées (une par tir)
            for ($i = 0; $i < $tir['nb_tirs']; $i++) {
                $entry = new TirFfbb();
                $entry->setRencontre($rencontre);
                $entry->setNomJoueuse($tir['nom']);
                $entry->setEstReussi(true);
                $entry->setSource(TirFfbb::SOURCE_FFBB);
                $entry->setTypeTir($tir['type'] ?? null);
                // position_x/y restent NULL en V1
                if ($joueurMatch !== null) {
                    $entry->setJoueur($joueurMatch);
                }
                $this->em->persist($entry);
                $count++;
            }
        }

        $this->em->flush();
        $this->logger->info('Parser positiontir FFBB terminé', [
            'rencontre_id' => $rencontre->getId(),
            'tirs_extraits' => $count,
        ]);

        return $count;
    }

    /**
     * Extrait les paires (nom joueuse, nb tirs) du texte PDF.
     *
     * V1 : très approximatif. Tente de matcher des sections du type :
     *   "MILAPIE R."  ou  "LEFEVRE Jody"
     * suivies de chiffres dans la même zone.
     *
     * Pour avoir une extraction PARFAITE → V2 avec OCR + analyse visuelle.
     *
     * @return array<int, array{nom: string, nb_tirs: int, type?: string}>
     */
    private function extraireTirsFromText(string $texte): array
    {
        $result = [];
        $rows = preg_split('/\r\n|\r|\n/', $texte) ?: [];

        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') continue;

            // Pattern : NOM Prénom (avec ou sans N°) suivi d'un compteur
            // Très tolérant car FFBB varie le format
            if (preg_match('/([A-ZÀ-Ÿ]{2,}[a-zà-ÿ\-\'\s]*[A-Za-zà-ÿ\.]+)\s*[\(\)]?\d*[\)\s]+.*?(\d+)\s*tir/iu', $row, $m)) {
                $nom = trim($m[1]);
                $nbTirs = (int) $m[2];
                if ($nbTirs > 0 && $nbTirs <= 30 && strlen($nom) > 2) {
                    $result[] = ['nom' => $nom, 'nb_tirs' => $nbTirs];
                }
            }
        }

        return $result;
    }

    /** @param \App\Entity\Sport\Joueur[] $joueurs */
    private function matchJoueurParNom(string $nomPdf, array $joueurs): ?\App\Entity\Sport\Joueur
    {
        $nomPdfNorm = $this->normaliser($nomPdf);
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($joueurs as $j) {
            $nomJoueur = $this->normaliser($j->getNom() . ' ' . $j->getPrenom());
            $distance = levenshtein($nomPdfNorm, $nomJoueur);
            if ($distance < $bestScore && $distance <= 3) {
                $bestScore = $distance;
                $bestMatch = $j;
            }
        }
        return $bestMatch;
    }

    private function normaliser(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c']);
        return preg_replace('/\s+/', ' ', $s);
    }
}
