<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EvaluationMatchRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;

/**
 * [B22b-bis V2 — 15/06/2026] Parse un .xlsx d'évaluations rempli par le coach
 * et persiste les EvaluationMatch en masse.
 *
 * Compatible avec le template généré par EvaluationMatchXlsxExporter (colonnes
 * en position fixe). Idempotent : ré-importer le même fichier met à jour
 * les EvaluationMatch existantes au lieu de créer des doublons.
 *
 * Stratégie de matching joueuse :
 *   1. ID en colonne A (technique, fiable à 100%) → recherche directe
 *   2. Si A vide ou invalide → fallback Levenshtein sur nom + prénom en colonne B
 *   3. Si aucun match → ligne ignorée + log warning
 */
class EvaluationMatchXlsxImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JoueurRepository $joueurRepo,
        private readonly EvaluationMatchRepository $evalRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Importe le fichier et retourne un récap {created, updated, skipped, errors}.
     *
     * @return array{created: int, updated: int, skipped: int, errors: string[]}
     */
    public function importFromFile(string $filePath, Rencontre $rencontre): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            $result['errors'][] = 'Fichier illisible : ' . $e->getMessage();
            return $result;
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        // === Pré-charge cache joueuses du club pour fallback levenshtein ===
        $joueursClub = $this->joueurRepo->findBy([
            'equipe' => $rencontre->getEquipe(),
            'isActive' => true,
        ]);

        // === Pré-charge évals existantes pour mode incrémental (update vs create) ===
        $evalsExistantes = $this->evalRepo->evaluationsRencontre($rencontre);
        $evalsParJoueur = [];
        foreach ($evalsExistantes as $e) {
            $evalsParJoueur[$e->getJoueur()->getId()] = $e;
        }

        // === Parcours des lignes (à partir de la ligne 5 = première joueuse selon template) ===
        for ($row = 5; $row <= $highestRow; $row++) {
            $idCell = trim((string) $sheet->getCell('A' . $row)->getValue());
            $nameCell = trim((string) $sheet->getCell('B' . $row)->getValue());

            // Ligne vide → on saute (peut arriver si le coach a effacé une ligne)
            if ($idCell === '' && $nameCell === '') {
                continue;
            }

            // === Match joueuse ===
            $joueur = null;
            if (ctype_digit($idCell)) {
                $joueur = $this->joueurRepo->find((int) $idCell);
                // Sécurité : la joueuse doit appartenir à l'équipe de la rencontre
                if ($joueur !== null && $joueur->getEquipe()?->getId() !== $rencontre->getEquipe()?->getId()) {
                    $result['errors'][] = sprintf(
                        'Ligne %d : joueuse ID %d n\'appartient pas à l\'équipe de la rencontre. Ignorée.',
                        $row,
                        (int) $idCell
                    );
                    continue;
                }
            }

            // Fallback : matching par nom levenshtein (tolérance 3 caractères)
            if ($joueur === null && $nameCell !== '') {
                $joueur = $this->trouverJoueurParNom($nameCell, $joueursClub);
                if ($joueur === null) {
                    $result['errors'][] = sprintf(
                        'Ligne %d : joueuse "%s" introuvable. Ignorée.',
                        $row,
                        $nameCell
                    );
                    continue;
                }
            }

            if ($joueur === null) {
                continue;
            }

            // === Lecture des valeurs (template FFBB-only, 14 colonnes A-N) ===
            // Champs FFBB disponibles dans le PDF resume : minutes, points (calculé),
            // tirs 2pt R/T, tirs 3pt R/T, LF R/T, fautes commises.
            // Les stats AVANCÉES (PD, INT, CTR, rebonds, pertes) ne viennent PAS de la FFBB
            // → restent à 0 dans EvaluationMatch (alimentées par Stats Live si dispo).
            $values = [
                'starter'   => (int) $sheet->getCell('D' . $row)->getValue() === 1,
                'minutes'   => $this->clampInt($sheet->getCell('E' . $row)->getValue(), 0, 60),
                // F = Points (calculé en Excel par le coach mais recalculé côté serveur pour cohérence)
                't2r'       => $this->clampInt($sheet->getCell('G' . $row)->getValue()),
                't2t'       => $this->clampInt($sheet->getCell('H' . $row)->getValue()),
                't3r'       => $this->clampInt($sheet->getCell('I' . $row)->getValue()),
                't3t'       => $this->clampInt($sheet->getCell('J' . $row)->getValue()),
                'lfr'       => $this->clampInt($sheet->getCell('K' . $row)->getValue()),
                'lft'       => $this->clampInt($sheet->getCell('L' . $row)->getValue()),
                'fc'        => $this->clampInt($sheet->getCell('M' . $row)->getValue()),
                'notes'     => trim((string) $sheet->getCell('N' . $row)->getValue()),
                // Stats avancées NON disponibles dans la FFBB — préservées si déjà saisies
                // via Stats Live (n'écrasent pas les valeurs existantes)
                'ro'        => $evalsParJoueur[$joueur->getId()]?->getRebondsOffensifs() ?? 0,
                'rd'        => $evalsParJoueur[$joueur->getId()]?->getRebondsDefensifs() ?? 0,
                'pd'        => $evalsParJoueur[$joueur->getId()]?->getPassesDecisives() ?? 0,
                'int'       => $evalsParJoueur[$joueur->getId()]?->getInterceptions() ?? 0,
                'ctr'       => $evalsParJoueur[$joueur->getId()]?->getContres() ?? 0,
                'pb'        => $evalsParJoueur[$joueur->getId()]?->getPertesBalle() ?? 0,
            ];

            // Skip si tout est à 0 et pas de notes : ligne vide, joueuse pas convoquée
            if ($this->estLigneVide($values)) {
                $result['skipped']++;
                continue;
            }

            // === Cohérence : R ≤ T ===
            if ($values['t2r'] > $values['t2t']) $values['t2t'] = $values['t2r'];
            if ($values['t3r'] > $values['t3t']) $values['t3t'] = $values['t3r'];
            if ($values['lfr'] > $values['lft']) $values['lft'] = $values['lfr'];

            // === Récup ou création EvaluationMatch ===
            $eval = $evalsParJoueur[$joueur->getId()] ?? null;
            $isNew = ($eval === null);
            if ($isNew) {
                $eval = new EvaluationMatch();
                $eval->setJoueur($joueur);
                $eval->setRencontre($rencontre);
            }

            $eval->setIsStarter($values['starter']);
            $eval->setMinutesJouees($values['minutes']);
            $eval->setTirs2ptsReussis($values['t2r']);
            $eval->setTirs2ptsTentes($values['t2t']);
            $eval->setTirs3ptsReussis($values['t3r']);
            $eval->setTirs3ptsTentes($values['t3t']);
            $eval->setLancersReussis($values['lfr']);
            $eval->setLancersTentes($values['lft']);
            $eval->setRebondsOffensifs($values['ro']);
            $eval->setRebondsDefensifs($values['rd']);
            $eval->setPassesDecisives($values['pd']);
            $eval->setInterceptions($values['int']);
            $eval->setContres($values['ctr']);
            $eval->setFautesCommises($values['fc']);
            $eval->setPertesBalle($values['pb']);
            $eval->setNotesCoach($values['notes'] !== '' ? $values['notes'] : null);

            if ($isNew) {
                $this->em->persist($eval);
                $result['created']++;
            } else {
                $result['updated']++;
            }
        }

        $this->em->flush();

        $this->logger->info('Import Excel evals terminé', [
            'rencontre_id' => $rencontre->getId(),
            'created'      => $result['created'],
            'updated'      => $result['updated'],
            'skipped'      => $result['skipped'],
            'errors_count' => count($result['errors']),
        ]);

        return $result;
    }

    /**
     * Cast en int + clamp dans [min, max].
     */
    private function clampInt(mixed $value, int $min = 0, int $max = 999): int
    {
        $i = (int) $value;
        if ($i < $min) return $min;
        if ($i > $max) return $max;
        return $i;
    }

    /**
     * "Ligne vide" = uniquement les colonnes FFBB du template à 0.
     * On ne check PAS les stats avancées (PD, INT, etc.) car celles-ci
     * peuvent être déjà saisies via Stats Live et préservées par l'import.
     */
    private function estLigneVide(array $v): bool
    {
        return $v['minutes'] === 0
            && $v['t2r'] === 0 && $v['t2t'] === 0
            && $v['t3r'] === 0 && $v['t3t'] === 0
            && $v['lfr'] === 0 && $v['lft'] === 0
            && $v['fc'] === 0
            && $v['notes'] === '';
    }

    /**
     * Fallback : trouve la joueuse par nom complet avec tolérance levenshtein.
     *
     * @param \App\Entity\Sport\Joueur[] $joueurs
     */
    private function trouverJoueurParNom(string $nomPdf, array $joueurs): ?\App\Entity\Sport\Joueur
    {
        $nomNorm = $this->normaliser($nomPdf);
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($joueurs as $j) {
            $candidat1 = $this->normaliser($j->getNom() . ' ' . $j->getPrenom());
            $candidat2 = $this->normaliser($j->getPrenom() . ' ' . $j->getNom());
            $distance = min(levenshtein($nomNorm, $candidat1), levenshtein($nomNorm, $candidat2));
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
