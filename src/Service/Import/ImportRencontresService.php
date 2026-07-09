<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import des rencontres FFBB depuis un xlsx « Rechercher une rencontre ».
 *
 * Logique extraite de ImportRencontresFromXlsxCommand pour être réutilisable à
 * la fois par la commande CLI et par le contrôleur web du Manager (upload).
 *
 * STRUCTURE xlsx attendue (en-tête ligne 1, colonnes en position fixe) :
 *   Division | N° de match | Equipe 1 | Equipe 2 | Date | Heure | Salle |
 *   e-Marque V2 | Score 1 | Forfait 1 | Score 2 | Forfait 2
 *
 * DÉTECTION DE « NOTRE ÉQUIPE » (multi-club, sans nom codé en dur) :
 *   Un export FFBB filtré sur une équipe contient CETTE équipe dans toutes les
 *   lignes. On identifie donc « notre équipe » comme le nom qui revient le plus
 *   souvent dans les colonnes Equipe 1 / Equipe 2. Marche pour n'importe quel
 *   club. Un indice optionnel ($nomHint, sous-chaîne) permet de forcer le côté
 *   (utilisé par la commande CLI via --club-mabb-pattern).
 *
 * Idempotent : clé unique (club, equipe, numeroMatch, saison) — relancer
 * n'crée pas de doublon.
 */
class ImportRencontresService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *   equipe_detectee: ?string,
     *   stats: array{lignes_lues:int, exempt:int, pas_equipe:int, deja_en_base:int, creees:int, erreurs:int},
     *   erreurs: list<string>,
     *   apercu: list<array{domicile:bool, adversaire:string, date:string, numero:string, division:?string, score:string}>
     * }
     */
    public function importFromFile(
        string $filePath,
        Club $club,
        Equipe $equipe,
        string $saison,
        bool $dryRun = false,
        ?string $nomHint = null,
    ): array {
        $stats = [
            'lignes_lues'  => 0,
            'exempt'       => 0,
            'pas_equipe'   => 0,
            'deja_en_base' => 0,
            'creees'       => 0,
            'erreurs'      => 0,
        ];
        $erreurs = [];
        $apercu  = [];

        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $rows  = $sheet->toArray();

        // Retire l'en-tête (1re ligne).
        array_shift($rows);

        // --- Passe 1 : déterminer « notre équipe » ---
        $nomEquipe = $this->detecterNotreEquipe($rows, $nomHint);

        $rencontreRepo = $this->em->getRepository(Rencontre::class);
        $aEcrire = false;

        // --- Passe 2 : création des rencontres ---
        foreach ($rows as $i => $row) {
            [
                $division, $numeroMatch, $equipe1, $equipe2, $dateStr, $heureStr,
                $salle, $codeEmarque, $score1, $forfait1, $score2, $forfait2
            ] = array_pad($row, 12, null);

            // Ligne vide
            if (empty($numeroMatch) || empty($equipe1) || empty($equipe2)) {
                continue;
            }
            $stats['lignes_lues']++;

            // Exempt (journée sans adversaire)
            if (stripos((string) $equipe1, 'exempt') !== false || stripos((string) $equipe2, 'exempt') !== false) {
                $stats['exempt']++;
                continue;
            }

            $nom1 = $this->cleanNomEquipe((string) $equipe1);
            $nom2 = $this->cleanNomEquipe((string) $equipe2);

            // Quel côté est « nous » ?
            if ($nomEquipe !== null && $nom1 === $nomEquipe) {
                $domicile   = true;
                $adversaire = $nom2;
            } elseif ($nomEquipe !== null && $nom2 === $nomEquipe) {
                $domicile   = false;
                $adversaire = $nom1;
            } else {
                $stats['pas_equipe']++;
                continue;
            }

            // Idempotence
            $existe = $rencontreRepo->findOneBy([
                'club'        => $club,
                'equipe'      => $equipe,
                'numeroMatch' => (string) $numeroMatch,
                'saison'      => $saison,
            ]);
            if ($existe !== null) {
                $stats['deja_en_base']++;
                continue;
            }

            // Date
            try {
                $date = $this->parseDateFfbb((string) $dateStr, (string) $heureStr);
            } catch (\Throwable $e) {
                $stats['erreurs']++;
                $erreurs[] = sprintf('Ligne %d : date invalide « %s %s ».', $i + 2, $dateStr, $heureStr);
                continue;
            }

            $scoreNous = $domicile ? $score1 : $score2;
            $scoreAdv  = $domicile ? $score2 : $score1;

            $rencontre = new Rencontre();
            $rencontre->setClub($club);
            $rencontre->setEquipe($equipe);
            $rencontre->setDate($date);
            $rencontre->setAdversaire($adversaire);
            $rencontre->setDomicile($domicile);
            $rencontre->setLieu($salle !== null && $salle !== '' ? trim((string) $salle) : null);
            $rencontre->setNumeroMatch((string) $numeroMatch);
            $rencontre->setSaison($saison);
            $rencontre->setDivision($division !== null && $division !== '' ? trim((string) $division) : null);
            $rencontre->setCodeEmarque($codeEmarque !== null && $codeEmarque !== '' ? trim((string) $codeEmarque) : null);

            if (is_numeric($scoreNous)) {
                $rencontre->setScoreEquipe((int) $scoreNous);
            }
            if (is_numeric($scoreAdv)) {
                $rencontre->setScoreAdverse((int) $scoreAdv);
            }
            if (method_exists($rencontre, 'setForfaitEquipe')) {
                $rencontre->setForfaitEquipe(strcasecmp(trim((string) ($domicile ? $forfait1 : $forfait2)), 'oui') === 0);
            }
            if (method_exists($rencontre, 'setForfaitAdverse')) {
                $rencontre->setForfaitAdverse(strcasecmp(trim((string) ($domicile ? $forfait2 : $forfait1)), 'oui') === 0);
            }
            if (method_exists($rencontre, 'setStatut')) {
                $rencontre->setStatut($date < new \DateTimeImmutable() ? 'joue' : 'a_venir');
            }

            $apercu[] = [
                'domicile'   => $domicile,
                'adversaire' => $adversaire,
                'date'       => $date->format('d/m/Y H:i'),
                'numero'     => (string) $numeroMatch,
                'division'   => $division !== null && $division !== '' ? trim((string) $division) : null,
                'score'      => (is_numeric($scoreNous) && is_numeric($scoreAdv)) ? "$scoreNous–$scoreAdv" : '—',
            ];

            if (!$dryRun) {
                $this->em->persist($rencontre);
                $aEcrire = true;
            }
            $stats['creees']++;
        }

        if (!$dryRun && $aEcrire) {
            $this->em->flush();
        }

        return [
            'equipe_detectee' => $nomEquipe,
            'stats'           => $stats,
            'erreurs'         => $erreurs,
            'apercu'          => $apercu,
        ];
    }

    /**
     * Détermine le nom de « notre équipe » :
     *   - si $nomHint fourni : le nom (nettoyé) qui contient cette sous-chaîne,
     *     le plus fréquent (comportement de la commande CLI --club-mabb-pattern) ;
     *   - sinon : le nom (nettoyé) le plus fréquent toutes lignes confondues.
     *
     * @param list<array<int, mixed>> $rows lignes de données (sans en-tête)
     */
    private function detecterNotreEquipe(array $rows, ?string $nomHint): ?string
    {
        $freq = [];
        foreach ($rows as $row) {
            [, $numeroMatch, $equipe1, $equipe2] = array_pad($row, 12, null);
            if (empty($numeroMatch) || empty($equipe1) || empty($equipe2)) {
                continue;
            }
            if (stripos((string) $equipe1, 'exempt') !== false || stripos((string) $equipe2, 'exempt') !== false) {
                continue;
            }
            foreach ([$this->cleanNomEquipe((string) $equipe1), $this->cleanNomEquipe((string) $equipe2)] as $nom) {
                if ($nom === '') {
                    continue;
                }
                $freq[$nom] = ($freq[$nom] ?? 0) + 1;
            }
        }

        if ($freq === []) {
            return null;
        }

        if ($nomHint !== null && $nomHint !== '') {
            $candidats = array_filter(
                $freq,
                static fn (string $nom): bool => mb_stripos($nom, $nomHint) !== false,
                ARRAY_FILTER_USE_KEY
            );
            if ($candidats === []) {
                return null;
            }
            arsort($candidats);

            return (string) array_key_first($candidats);
        }

        arsort($freq);

        return (string) array_key_first($freq);
    }

    private function parseDateFfbb(string $dateStr, string $heureStr): \DateTimeImmutable
    {
        $dateStr  = trim($dateStr);
        $heureStr = trim($heureStr !== '' ? $heureStr : '00:00');

        if ($dateStr === '') {
            throw new \InvalidArgumentException('Date vide.');
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dateStr, $m)) {
            $iso  = sprintf('%04d-%02d-%02d %s', (int) $m[3], (int) $m[2], (int) $m[1], $heureStr);
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $iso);
            if ($date === false) {
                throw new \InvalidArgumentException("Date ISO invalide : $iso");
            }

            return $date;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $heureStr);
        if ($date === false) {
            throw new \InvalidArgumentException("Format date non reconnu : « $dateStr $heureStr ».");
        }

        return $date;
    }

    private function cleanNomEquipe(string $nom): string
    {
        $clean = preg_replace('#\s*\(\d+\)\s*$#', '', $nom);

        return trim((string) $clean);
    }
}
