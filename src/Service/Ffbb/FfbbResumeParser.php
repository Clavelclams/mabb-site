<?php

declare(strict_types=1);

namespace App\Service\Ffbb;

use App\Entity\Sport\EvaluationFfbb;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EvaluationFfbbRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * [B22b 12/06/2026] Parser du PDF resume_*.pdf FFBB.
 *
 * Stratégie : extraction du TEXTE brut via smalot/pdfparser (déjà installé)
 * + regex pour identifier chaque ligne joueuse de la matrice statistique.
 *
 * Le PDF resume FFBB contient pour chaque équipe une grille standardisée :
 *   N° | Nom Prénom | Min | Pts | 2pts R/T | 3pts R/T | LF R/T | Reb O/D | Passes D | Inter | Contres | Fautes | Pertes | Eval
 *
 * Notre regex parse les lignes en cherchant le pattern "N° SUIVI D'UN NOM".
 * On stocke ce qu'on arrive à parser dans EvaluationFfbb, on rate gracefully
 * sur les lignes mal formées (FFBB change parfois le format mineur en mineur).
 *
 * Idempotent : on supprime les EvaluationFfbb existantes pour la rencontre
 * avant de réimporter.
 */
class FfbbResumeParser
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationFfbbRepository $evalRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    /**
     * Parse le PDF associé à la rencontre et persiste les EvaluationFfbb.
     * Retourne le nombre de joueuses parsées.
     */
    public function parseEtPersister(Rencontre $rencontre): int
    {
        $relativePath = $rencontre->getResumePath();
        if ($relativePath === null) {
            return 0;
        }

        $absolutePath = rtrim($this->projectDir, '/') . '/public/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            $this->logger->warning('Resume PDF introuvable', ['path' => $absolutePath]);
            return 0;
        }

        // Suppression idempotente
        foreach ($this->evalRepo->findForRencontre($rencontre) as $existing) {
            $this->em->remove($existing);
        }
        $this->em->flush();

        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($absolutePath);
            $texte = $pdf->getText();
        } catch (\Throwable $e) {
            $this->logger->error('Échec lecture PDF resume', ['error' => $e->getMessage()]);
            return 0;
        }

        $lignes = $this->extraireLignesJoueuses($texte);
        $clubMabb = $rencontre->getClub();
        $joueursDuClub = $this->joueurRepo->findBy(['club' => $clubMabb, 'isActive' => true]);

        $count = 0;
        foreach ($lignes as $ligne) {
            $eval = new EvaluationFfbb();
            $eval->setRencontre($rencontre);
            $eval->setNumeroMaillot($ligne['numero'] ?? null);
            $eval->setNomComplet($ligne['nom'] ?? '');
            $eval->setEstStarter($ligne['starter'] ?? false);
            $eval->setMinutesJouees($ligne['minutes'] ?? 0);
            $eval->setPoints($ligne['points'] ?? 0);
            $eval->setTirs2ptReussis($ligne['t2r'] ?? 0);
            $eval->setTirs2ptTentes($ligne['t2t'] ?? 0);
            $eval->setTirs3ptReussis($ligne['t3r'] ?? 0);
            $eval->setTirs3ptTentes($ligne['t3t'] ?? 0);
            $eval->setLancersReussis($ligne['lfr'] ?? 0);
            $eval->setLancersTentes($ligne['lft'] ?? 0);
            $eval->setRebondsOff($ligne['ro'] ?? 0);
            $eval->setRebondsDef($ligne['rd'] ?? 0);
            $eval->setPassesD($ligne['pd'] ?? 0);
            $eval->setInterceptions($ligne['int'] ?? 0);
            $eval->setContres($ligne['ctr'] ?? 0);
            $eval->setFautesCommises($ligne['ftc'] ?? 0);
            $eval->setPertesBalle($ligne['pb'] ?? 0);
            $eval->setEvalFfbb($ligne['eval'] ?? 0);

            // Match Joueur par nom (best-effort)
            $joueurMatch = $this->matchJoueurParNom($ligne['nom'] ?? '', $joueursDuClub);
            if ($joueurMatch !== null) {
                $eval->setJoueur($joueurMatch);
            }

            $this->em->persist($eval);
            $count++;
        }

        $this->em->flush();
        $this->logger->info('Parser resume FFBB terminé', [
            'rencontre_id' => $rencontre->getId(),
            'lignes_parsees' => $count,
        ]);

        return $count;
    }

    /**
     * Extrait les lignes "joueuse + stats" du texte brut du PDF.
     *
     * Heuristique simple : chaque ligne intéressante commence par
     * un numéro de maillot (1-2 chiffres) suivi d'un nom en MAJ.
     *
     * Format observé sur les PDFs FFBB 2025-2026 :
     *   "10 LEFEVRE Jody   25:30   12  2/5  1/3  3/3   2/4  4  3  2  0  3  2  15"
     *   (N° NOM Prénom   min   pts  2pts  3pts  LF   Reb O/D  PD  Int  Ctr  Ftc  PB  Eval)
     *
     * NOTE : ce parser est volontairement "best-effort". Si le format FFBB
     * change, on ne plante pas — on extrait ce qu'on peut.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extraireLignesJoueuses(string $texte): array
    {
        $lignes = [];
        $rows = preg_split('/\r\n|\r|\n/', $texte) ?: [];

        foreach ($rows as $row) {
            $row = trim($row);
            if ($row === '') continue;

            // Pattern : numéro + nom + au moins 3 nombres derrière
            // Très tolérant : on capture le numéro + 1+ mots de nom + une série de nombres
            if (!preg_match('/^\*?\s*(\d{1,2})\s+([A-ZÀ-Ÿ][A-ZÀ-Ÿa-z\-\'éÉèÈêÊàÀâÂîÎôÔûÛçÇ\s]{2,40})\s+([\d:\/\s]+)$/u', $row, $m)) {
                continue;
            }

            $estStarter = str_starts_with($row, '*'); // FFBB marque parfois les titulaires avec *
            $numero = (int) $m[1];
            $nom = trim($m[2]);
            $nombres = preg_split('/\s+/', trim($m[3])) ?: [];

            // Si pas assez de nombres → ligne pas valide
            if (count($nombres) < 5) continue;

            $idx = 0;
            $minutes = $this->parseTime($nombres[$idx++] ?? '0');
            $points  = (int) ($nombres[$idx++] ?? 0);
            [$t2r, $t2t] = $this->parseRatio($nombres[$idx++] ?? '0/0');
            [$t3r, $t3t] = $this->parseRatio($nombres[$idx++] ?? '0/0');
            [$lfr, $lft] = $this->parseRatio($nombres[$idx++] ?? '0/0');
            [$ro, $rd]   = $this->parseRatio($nombres[$idx++] ?? '0/0');
            $pd = (int) ($nombres[$idx++] ?? 0);
            $int = (int) ($nombres[$idx++] ?? 0);
            $ctr = (int) ($nombres[$idx++] ?? 0);
            $ftc = (int) ($nombres[$idx++] ?? 0);
            $pb = (int) ($nombres[$idx++] ?? 0);
            $eval = (int) ($nombres[$idx++] ?? 0);

            $lignes[] = compact('numero', 'nom', 'estStarter', 'minutes', 'points', 't2r', 't2t', 't3r', 't3t', 'lfr', 'lft', 'ro', 'rd', 'pd', 'int', 'ctr', 'ftc', 'pb', 'eval')
                + ['starter' => $estStarter];
        }

        return $lignes;
    }

    /** "25:30" → 25 minutes (on ignore secondes pour stats globales). */
    private function parseTime(string $s): int
    {
        if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
            return (int) $m[1];
        }
        return (int) $s;
    }

    /** "3/5" → [3, 5] */
    private function parseRatio(string $s): array
    {
        if (preg_match('/^(\d+)\/(\d+)$/', $s, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, 0];
    }

    /**
     * Match best-effort d'un nom complet PDF avec un Joueur du club.
     * Compare en LEVENSHTEIN tolérant pour gérer les variations de saisie.
     *
     * @param \App\Entity\Sport\Joueur[] $joueurs
     */
    private function matchJoueurParNom(string $nomPdf, array $joueurs): ?\App\Entity\Sport\Joueur
    {
        $nomPdfNorm = $this->normaliser($nomPdf);
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($joueurs as $j) {
            $nomJoueur = $this->normaliser($j->getNom() . ' ' . $j->getPrenom());
            $distance = levenshtein($nomPdfNorm, $nomJoueur);
            if ($distance < $bestScore && $distance <= 3) { // tolérance 3 caractères
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
