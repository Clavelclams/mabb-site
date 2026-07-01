<?php

declare(strict_types=1);

namespace App\Service\Ffbb;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\TirFfbb;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\TirFfbbRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * [B22c 12/06/2026] Parser du PDF positiontir_*.pdf FFBB.
 *
 * ============================================================================
 * VERSION V2 (30/06/2026) — extraction graphique des tirs via Python + PyMuPDF
 * ============================================================================
 *
 * Structure du PDF e-Marque (A4 595×842 pts, 2 joueuses par page) :
 *   - 1 image 32×32 px = marqueur ⊙ tir réussi, placé à chaque tir
 *   - 1 image 506×470 px = template terrain (réutilisé ×12/page)
 *
 * Le script bin/ffbb_parse_positions.py :
 *   1. Lit le PDF via PyMuPDF (fitz)
 *   2. Récupère les positions de l'image 32×32 pour chaque joueuse
 *   3. Normalise en [0,1] dans le terrain agrégé gauche
 *   4. OCR du nom via Tesseract (eng)
 *   5. Sort JSON [{nom, prenom_initial, norm_x, norm_y}, ...]
 *
 * Prérequis sur le serveur :
 *   python3, pip install pymupdf pytesseract pillow
 *   tesseract (avec données eng)
 *
 * Coordonnées normalisées :
 *   norm_x = 0 → bord gauche du terrain
 *   norm_x = 1 → bord droit
 *   norm_y = 0 → côté panier (haut de la moitié de terrain)
 *   norm_y = 1 → milieu de terrain (bas)
 *
 * Stockage en BDD : position_x/y en entiers 0–100 (smallint).
 *
 * ============================================================================
 * VERSION V1 (12/06/2026) — extraction texte uniquement (ARCHIVÉE)
 * ============================================================================
 */
class FfbbPositionTirParser
{
    private const PYTHON_SCRIPT = 'bin/ffbb_parse_positions.py';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TirFfbbRepository $tirRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // API publique
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse le PDF positiontir_*.pdf et persiste les TirFfbb avec positions X/Y.
     *
     * Idempotent : supprime les anciens TirFfbb source=ffbb avant réinsertion.
     * Retourne le nombre de tirs insérés (0 si PDF absent ou Python indisponible).
     */
    public function parseEtPersister(Rencontre $rencontre, bool $dryRun = false): int
    {
        $pdfPath = $this->resolvePdfPath($rencontre);
        if ($pdfPath === null) {
            return 0;
        }

        // --- Appel Python -------------------------------------------------
        $rawShots = $this->callPythonParser($pdfPath);
        if ($rawShots === null) {
            // Python indisponible → log + 0
            $this->logger->warning(
                'FfbbPositionTirParser: Python/PyMuPDF indisponible. '
                . 'Installer python3 + pymupdf + pytesseract sur le serveur.',
                ['rencontre_id' => $rencontre->getId()]
            );
            return 0;
        }

        if (empty($rawShots)) {
            $this->logger->info('Aucun tir trouvé dans le PDF', ['rencontre_id' => $rencontre->getId()]);
            return 0;
        }

        // --- Roster MABB --------------------------------------------------
        $saison = $rencontre->getSaison() ?? '2025-2026';
        $equipe = $rencontre->getEquipe();
        $joueurs = $equipe !== null
            ? $this->joueurRepo->findByEquipeAffectation($equipe, $saison)
            : $this->joueurRepo->findBy(['club' => $rencontre->getClub(), 'isActive' => true]);

        // --- Suppression ancienne data ------------------------------------
        if (!$dryRun) {
            foreach ($this->tirRepo->findForRencontre($rencontre, TirFfbb::SOURCE_FFBB) as $old) {
                $this->em->remove($old);
            }
            $this->em->flush();
        }

        // --- Insertion ----------------------------------------------------
        $count = 0;
        $warnings = [];

        foreach ($rawShots as $shot) {
            $nomOcr = $shot['nom'] ?? '';
            $prenomInit = $shot['prenom_initial'] ?? '';

            $joueur = $this->matchJoueur($nomOcr, $prenomInit, $joueurs);
            if ($joueur === null) {
                $warnings[] = "{$nomOcr} {$prenomInit}.";
                continue;  // Pas une joueuse MABB → skip
            }

            $entry = new TirFfbb();
            $entry->setRencontre($rencontre);
            $entry->setJoueur($joueur);
            $entry->setNomJoueuse($joueur->getNom() . ' ' . substr($joueur->getPrenom() ?? '', 0, 1) . '.');
            $entry->setEstReussi(true);
            $entry->setSource(TirFfbb::SOURCE_FFBB);

            // Transformation coords PDF → coords zone (SVG shot map)
            // PDF : norm_y=0=panier (haut), norm_y=1=ligne médiane
            //       norm_x=0=sideline gauche, norm_x=1=sideline droit
            // Zone: x=depth (0.04=panier, 0.50=ligne médiane)
            //       y=lateral (0=sideline, 1=sideline opposé)
            $normX = (float)($shot['norm_x'] ?? 0.5);  // latéral PDF
            $normY = (float)($shot['norm_y'] ?? 0.5);  // profondeur PDF
            $zoneX = $normY * 0.46 + 0.04;             // [0.04 – 0.50]
            $zoneY = $normX;                            // [0.00 – 1.00]

            $entry->setPositionX($this->toInt($zoneX));
            $entry->setPositionY($this->toInt($zoneY));
            $entry->setTypeTir($this->classifyShot($zoneX, $zoneY));

            if (!$dryRun) {
                $this->em->persist($entry);
            }
            $count++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ($warnings) {
            $this->logger->info(
                'Tirs sans correspondance joueuse MABB (probablement équipe adverse)',
                ['non_matchees' => array_unique($warnings), 'rencontre_id' => $rencontre->getId()]
            );
        }

        $this->logger->info('FfbbPositionTirParser V2 terminé', [
            'rencontre_id' => $rencontre->getId(),
            'tirs_inseres' => $count,
            'dry_run' => $dryRun,
        ]);

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Privé
    // ─────────────────────────────────────────────────────────────────────────

    private function resolvePdfPath(Rencontre $rencontre): ?string
    {
        $rel = $rencontre->getPositionsTirsPath();
        if ($rel === null) {
            return null;
        }
        $abs = rtrim($this->projectDir, '/') . '/public/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            $this->logger->warning('Positiontir PDF introuvable', ['path' => $abs]);
            return null;
        }
        return $abs;
    }

    /**
     * Appelle le script Python et retourne le tableau de tirs décodés.
     * Retourne null si Python n'est pas disponible ou si le script échoue.
     *
     * @return array<int,array{nom:string,prenom_initial:string,norm_x:float,norm_y:float}>|null
     */
    private function callPythonParser(string $pdfPath): ?array
    {
        $scriptPath = rtrim($this->projectDir, '/') . '/' . self::PYTHON_SCRIPT;
        if (!is_file($scriptPath)) {
            $this->logger->error('Script Python introuvable', ['path' => $scriptPath]);
            return null;
        }

        // Chercher python3 dans les emplacements classiques
        $python = $this->findPython();
        if ($python === null) {
            return null;
        }

        $cmd = sprintf(
            '%s %s %s 2>/dev/null',
            escapeshellarg($python),
            escapeshellarg($scriptPath),
            escapeshellarg($pdfPath)
        );

        $output = shell_exec($cmd);
        if ($output === null || $output === '') {
            $this->logger->error('Script Python: aucune sortie', ['cmd' => $cmd]);
            return null;
        }

        $data = json_decode($output, true);
        if (!is_array($data)) {
            $this->logger->error('Script Python: JSON invalide', ['raw' => substr($output, 0, 200)]);
            return null;
        }

        return $data;
    }

    private function findPython(): ?string
    {
        foreach (['/usr/bin/python3', '/usr/local/bin/python3', 'python3', 'python'] as $candidate) {
            $check = shell_exec(sprintf('%s --version 2>/dev/null', escapeshellarg($candidate)));
            if ($check !== null && str_starts_with($check, 'Python 3')) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Matche le nom OCR (ex: "LEFEVRE", "J") contre les joueuses du roster.
     * Stratégie : NOM exact (insensible casse + diacritiques) + initiale prénom.
     * Fallback Levenshtein sur NOM seul si pas de match exact.
     *
     * @param Joueur[] $joueurs
     */
    private function matchJoueur(string $nomOcr, string $prenomInit, array $joueurs): ?Joueur
    {
        if ($nomOcr === '' || $nomOcr === 'INCONNU') {
            return null;
        }

        $nomOcrNorm = $this->norm($nomOcr);
        $prenomInitNorm = strtolower(trim($prenomInit));

        // Passe 1 : NOM exact + initiale prénom
        foreach ($joueurs as $j) {
            if ($this->norm($j->getNom()) === $nomOcrNorm) {
                if ($prenomInitNorm === '' || strtolower(substr($j->getPrenom() ?? '', 0, 1)) === $prenomInitNorm) {
                    return $j;
                }
            }
        }

        // Passe 2 : NOM exact sans vérif initiale UNIQUEMENT si prenomInit inconnu (vide/?)
        // → évite LEFEVRE A. (adverse) de matcher LEFEVRE J. (MABB)
        if ($prenomInitNorm === '') {
            foreach ($joueurs as $j) {
                if ($this->norm($j->getNom()) === $nomOcrNorm) {
                    return $j;
                }
            }
        }

        // Passe 3 : Levenshtein ≤ 2 sur NOM (fautes OCR légères)
        // Si prenomInit est connu, il doit correspondre pour éviter les faux positifs.
        $best = null;
        $bestDist = PHP_INT_MAX;
        foreach ($joueurs as $j) {
            $d = levenshtein($nomOcrNorm, $this->norm($j->getNom()));
            if ($d < $bestDist && $d <= 2) {
                // Vérifier l'initiale si elle est connue
                if ($prenomInitNorm !== ''
                    && strtolower(substr($j->getPrenom() ?? '', 0, 1)) !== $prenomInitNorm) {
                    continue;
                }
                $bestDist = $d;
                $best = $j;
            }
        }

        return $best;
    }

    /**
     * Classifie le tir selon les coordonnées ZONE (mêmes que le SVG shot chart).
     *
     * Repère : panier à (zoneX=0.04, zoneY=0.50).
     *
     * Raquette (2pt_int) → vérification rectangulaire alignée sur le SVG :
     *   rect SVG x="1" y="65" width="80" height="70" dans viewBox 374×200
     *   → zoneX ∈ [0, 81/374=0.217] et zoneY ∈ [65/200=0.325, 135/200=0.675]
     *   L'ancienne approche (dist < 0.08) ne couvrait qu'un cercle minuscule autour
     *   du panier et classait les tirs dans la raquette en dehors de ce rayon en 2pt_ext.
     *
     * Arc 3pts → rayon 100px dans le SVG → 100/374 ≈ 0.267 en zone normalisé.
     */
    private function classifyShot(float $zoneX, float $zoneY): string
    {
        // Raquette : check rectangulaire correspondant au rect SVG de la paint
        if ($zoneX <= 0.217 && $zoneY >= 0.325 && $zoneY <= 0.675) {
            return TirFfbb::TYPE_2PT_INT;
        }

        $px   = $zoneX - 0.04;
        $py   = $zoneY - 0.50;
        $dist = sqrt($px * $px + $py * $py);

        if ($dist > 0.267) return TirFfbb::TYPE_3PT;  // derrière l'arc 3pts (rayon 100/374)
        return TirFfbb::TYPE_2PT_EXT;
    }

    /** Convertit 0.0–1.0 en entier 0–100 pour stockage smallint. */
    private function toInt(?float $v): ?int
    {
        if ($v === null) {
            return null;
        }
        return (int) round(max(0.0, min(1.0, $v)) * 100);
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return strtr($s, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u',
            'ç' => 'c',
        ]);
    }
}
