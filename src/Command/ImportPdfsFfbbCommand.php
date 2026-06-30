<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Rencontre;
use App\Repository\Core\ClubRepository;
use App\Repository\Sport\RencontreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * B20 — Import des 3 PDFs FFBB par rencontre.
 *
 * Lit le dossier passé en --dir contenant des sous-dossiers nommés selon
 * la convention FFBB :
 *   0080_PRF_A_12_METROPOLE_AMIENOISE_BASKETBALL_ESC_LONGUEAU_AMIENS_MSBB-3/
 *      feuillematch_xxxxx.pdf
 *      positiontir_xxxxx.pdf
 *      resume_xxxxx.pdf
 *
 * Formats de dossiers supportés :
 *   - "0080_PRF_A_12_METROPOLE_..."             → division=PRF,  num=12, leg=null
 *   - "0002_BPRF_ALLER_A_1_ESC_..."             → division=BPRF, num=1,  leg=ALLER
 *   - "0002_BPRF_RETOUR_A_1_METROPOLE_..."      → division=BPRF, num=1,  leg=RETOUR
 *   - "0080_DFU15-P2_A_11_METROPOLE_..."        → division=DFU15-P2, num=11, leg=null
 *
 * Matching DB : division + numeroMatch + saison + club
 * Pour BPRF ALLER/RETOUR : disambiguation via domicile (ALLER=extérieur, RETOUR=domicile)
 *
 * Usage :
 *   # Dry-run pour vérifier avant de lancer
 *   php bin/console app:import-pdfs-ffbb --dir="~/ressource/rencontre senior" --saison=2025-2026 --dry-run
 *
 *   # Import réel
 *   php bin/console app:import-pdfs-ffbb --dir="~/ressource/rencontre senior" --saison=2025-2026
 *
 *   # Forcer l'écrasement de PDFs déjà présents
 *   php bin/console app:import-pdfs-ffbb --dir="~/ressource/rencontre u15b" --saison=2025-2026 --force
 */
#[AsCommand(
    name: 'app:import-pdfs-ffbb',
    description: 'Import les 3 PDFs FFBB par rencontre depuis un dossier ressource',
)]
class ImportPdfsFfbbCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepo,
        private readonly RencontreRepository $rencontreRepo,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir',        null, InputOption::VALUE_REQUIRED, 'Dossier ressource contenant les sous-dossiers par match')
            ->addOption('club-slug',  null, InputOption::VALUE_OPTIONAL, 'Slug du club cible', 'mabb')
            ->addOption('saison',     null, InputOption::VALUE_OPTIONAL, 'Saison cible', '2025-2026')
            ->addOption('dry-run',    null, InputOption::VALUE_NONE,     'Affiche sans copier ni modifier la BDD')
            ->addOption('force',      null, InputOption::VALUE_NONE,     'Écrase les PDFs déjà présents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir       = (string) $input->getOption('dir');
        $clubSlug  = (string) $input->getOption('club-slug');
        $saison    = (string) $input->getOption('saison');
        $dryRun    = (bool) $input->getOption('dry-run');
        $force     = (bool) $input->getOption('force');

        if (!is_dir($dir)) {
            $io->error("Dossier introuvable : {$dir}");
            return Command::FAILURE;
        }

        $club = $this->clubRepo->findOneBy(['slug' => $clubSlug]);
        if ($club === null) {
            $io->error("Club '{$clubSlug}' introuvable.");
            return Command::FAILURE;
        }

        $uploadRoot = rtrim($this->projectDir, '/') . '/public/uploads/rencontres';
        if (!is_dir($uploadRoot) && !$dryRun) {
            mkdir($uploadRoot, 0775, true);
        }

        $stats  = ['matched' => 0, 'no_match' => 0, 'copied' => 0, 'skipped' => 0, 'errors' => 0];
        $rapport = [];

        // === Scan des sous-dossiers ===
        $entries = scandir($dir);
        if ($entries === false) {
            $io->error("Lecture impossible : {$dir}");
            return Command::FAILURE;
        }

        foreach ($entries as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $sub = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($sub)) {
                continue;
            }

            // --- Parse le nom du dossier FFBB ---
            $parsed = $this->parseFolderName($entry);
            if ($parsed === null) {
                $io->note("Skip dossier (format non reconnu) : {$entry}");
                continue;
            }

            ['division' => $division, 'numMatch' => $numMatch, 'leg' => $leg] = $parsed;

            // --- Matching Rencontre en BDD ---
            $rencontre = $this->findRencontre($club, $division, $numMatch, $saison, $leg);

            if ($rencontre === null) {
                $stats['no_match']++;
                $rapport[] = ['NO MATCH', "Division={$division} N°{$numMatch}" . ($leg ? " leg={$leg}" : '') . " — dossier : $entry"];
                continue;
            }
            $stats['matched']++;

            // --- Copie des 3 PDFs ---
            $pdfs = [
                'feuille'   => $this->findPdf($sub, 'feuillematch'),
                'positions' => $this->findPdf($sub, 'positiontir'),
                'resume'    => $this->findPdf($sub, 'resume'),
            ];

            $targetDir = $uploadRoot . '/' . $rencontre->getId();
            if (!is_dir($targetDir) && !$dryRun) {
                mkdir($targetDir, 0775, true);
            }

            foreach ($pdfs as $type => $sourcePath) {
                if ($sourcePath === null) {
                    $rapport[] = ['MISSING', "Division={$division} N°{$numMatch} : pas de PDF '{$type}'"];
                    continue;
                }

                // Chemin relatif pour stockage BDD (convention "case 2" dans RencontrePdfUploader)
                $relativePath   = sprintf('uploads/rencontres/%d/%s.pdf', $rencontre->getId(), $type);
                $absoluteTarget = $uploadRoot . '/' . $rencontre->getId() . '/' . $type . '.pdf';

                // Skip si déjà uploadé et pas --force
                $existingPath = $rencontre->getPdfPath($type);
                if ($existingPath !== null && file_exists($this->projectDir . '/public/' . $existingPath) && !$force) {
                    $stats['skipped']++;
                    $rapport[] = ['SKIP', "Division={$division} N°{$numMatch} '{$type}' déjà présent (--force pour écraser)"];
                    continue;
                }

                if (!$dryRun) {
                    if (!copy($sourcePath, $absoluteTarget)) {
                        $stats['errors']++;
                        $rapport[] = ['ERROR', "Échec copie Division={$division} N°{$numMatch} '{$type}'"];
                        continue;
                    }
                    $rencontre->setPdfPath($type, $relativePath);
                }

                $stats['copied']++;
                $rapport[] = ['COPY', "Division={$division} N°{$numMatch} '{$type}' → {$relativePath}" . ($dryRun ? ' [DRY]' : '')];
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['Action', 'Détail'], $rapport);
        $io->success(sprintf(
            "%s : %d rencontres matchées, %d non matchées, %d PDFs copiés, %d skip, %d erreurs",
            $dryRun ? 'DRY-RUN' : 'IMPORT',
            $stats['matched'],
            $stats['no_match'],
            $stats['copied'],
            $stats['skipped'],
            $stats['errors'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Parse le nom d'un dossier FFBB et retourne [division, numMatch, leg].
     *
     * DEUX formats supportés :
     *
     * 1. FORMAT SOMME (compétitions départementales) :
     *    {dept}_{division}_[ALLER|RETOUR_]?A_{journee}_{teams}
     *    "0080_PRF_A_12_METROPOLE_..."         → division=PRF,      num=12, leg=null
     *    "0002_BPRF_ALLER_A_1_ESC_..."         → division=BPRF,     num=1,  leg=ALLER
     *    "0002_BPRF_RETOUR_A_1_METROPOLE_..."  → division=BPRF,     num=1,  leg=RETOUR
     *    "0080_DFU15-P2_A_11_METROPOLE_..."    → division=DFU15-P2, num=11, leg=null
     *    Le num = numéro de journée FFBB, ex : 12, 5, 27…
     *
     * 2. FORMAT HDF (compétitions régionales Hauts-de-France) :
     *    HDF_{division}_{phase}_{matchid}_{teams}
     *    "HDF_RFU13-2_B_5632_METROPOLE_..."        → division=RFU13-2,    num=5632
     *    "HDF_RFU13_R1-A_6317_METROPOLE_..."       → division=RFU13,      num=6317
     *    "HDF_IRFU18_J_5418_METROPOLE_..."          → division=IRFU18,     num=5418
     *    "HDF_RFU15-P2_R1BP2_7567_BEAUVAIS_..."    → division=RFU15-P2,   num=7567
     *    "HDF_RFU15-P2-P2_R1CL3_8110_BC_..."       → division=RFU15-P2-P2,num=8110
     *    Le num = ID de match FFBB à 4 chiffres (stocké tel quel dans numero_match).
     *
     * @return array{division: string, numMatch: string, leg: string|null}|null
     */
    private function parseFolderName(string $folderName): ?array
    {
        // Format Somme : 0080_PRF_A_12_... ou 0002_BPRF_ALLER_A_1_...
        if (preg_match('/^\d{4}_([A-Z0-9-]+)_(?:(ALLER|RETOUR)_)?A_(\d+)_/', $folderName, $m)) {
            return [
                'division' => $m[1],
                'numMatch' => $m[3],
                'leg'      => $m[2] !== '' ? $m[2] : null,
            ];
        }

        // Format HDF : HDF_{division}_{phase}_{matchid}_{teams}
        // La phase peut contenir des lettres, chiffres et tirets (B, J, R1-A, R1BP2, R2CL2…)
        if (preg_match('/^HDF_([A-Z0-9-]+)_[A-Z0-9-]+_(\d+)_/', $folderName, $m)) {
            return [
                'division' => $m[1],
                'numMatch' => $m[2],
                'leg'      => null,
            ];
        }

        return null;
    }

    /**
     * Recherche une Rencontre en BDD via division + numeroMatch + saison + club.
     *
     * Pour les matchs BPRF avec ALLER/RETOUR (même numéro, même division) :
     *   - ALLER   → MABB joue à l'extérieur → domicile = false
     *   - RETOUR  → MABB joue à domicile    → domicile = true
     *
     * Pour toutes les autres divisions, la combinaison division+num+saison+club
     * est unique dans la BDD → findOneBy suffit.
     */
    private function findRencontre(object $club, string $division, string $numMatch, string $saison, ?string $leg): ?Rencontre
    {
        $criteria = [
            'club'        => $club,
            'division'    => $division,
            'numeroMatch' => $numMatch,
            'saison'      => $saison,
        ];

        // BPRF ALLER/RETOUR : même num → disambiguïser par domicile
        if ($leg === 'ALLER') {
            $criteria['domicile'] = false;
        } elseif ($leg === 'RETOUR') {
            $criteria['domicile'] = true;
        }

        return $this->rencontreRepo->findOneBy($criteria);
    }

    /**
     * Trouve le premier PDF dans $dir dont le nom commence par $prefix.
     * Retourne null si absent.
     */
    private function findPdf(string $dir, string $prefix): ?string
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '_*.pdf') ?: [];
        return $files[0] ?? null;
    }
}
