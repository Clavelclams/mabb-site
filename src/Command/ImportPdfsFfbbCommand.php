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
 * Le N° de match est extrait du nom du dossier :
 *   - Format détecté : 0080_PRF_A_{NUM}_...   (compet régulière)
 *   - Format détecté : 0002_BPRF_ALLER_A_{NUM}_... (barrage / coupe)
 *
 * Logique :
 *   - Pour chaque sous-dossier, match la Rencontre via numero_match + saison + club
 *   - Copie les 3 PDFs dans public/uploads/rencontres/{id}/{type}.pdf
 *   - Update les champs resumePath / feuilleMatchPath / positionsTirsPath
 *   - Idempotent : skip si déjà présent (sauf --force)
 *
 * Usage :
 *   php bin/console app:import-pdfs-ffbb --dir="C:/.../rencontre senior" --saison=2025-2026
 *   php bin/console app:import-pdfs-ffbb --dir=... --dry-run
 *   php bin/console app:import-pdfs-ffbb --dir=... --force
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
            ->addOption('dry-run',    null, InputOption::VALUE_NONE,     'Affiche sans copier')
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

        $stats = ['matched' => 0, 'no_match' => 0, 'copied' => 0, 'skipped' => 0, 'errors' => 0];
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

            // Extract numéro match
            $numMatch = $this->extractNumeroMatch($entry);
            if ($numMatch === null) {
                $io->note("Skip dossier (pas de n° détecté) : {$entry}");
                continue;
            }

            // Recherche Rencontre
            $rencontre = $this->rencontreRepo->findOneBy([
                'club'        => $club,
                'numeroMatch' => $numMatch,
                'saison'      => $saison,
            ]);

            if ($rencontre === null) {
                $stats['no_match']++;
                $rapport[] = ['NO MATCH', "N° $numMatch — dossier : $entry"];
                continue;
            }
            $stats['matched']++;

            // Cherche les 3 PDFs
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
                    $rapport[] = ['MISSING', "N° $numMatch : pas de PDF '$type'"];
                    continue;
                }

                // Path relative pour stockage en BDD
                $relativePath = sprintf('uploads/rencontres/%d/%s.pdf', $rencontre->getId(), $type);
                $absoluteTarget = $uploadRoot . '/' . $rencontre->getId() . '/' . $type . '.pdf';

                // Skip si déjà uploadé et pas --force
                $existingPath = $rencontre->getPdfPath($type);
                if ($existingPath !== null && file_exists($this->projectDir . '/public/' . $existingPath) && !$force) {
                    $stats['skipped']++;
                    $rapport[] = ['SKIP', "N° $numMatch '$type' déjà uploadé (utilise --force pour écraser)"];
                    continue;
                }

                if (!$dryRun) {
                    if (!copy($sourcePath, $absoluteTarget)) {
                        $stats['errors']++;
                        $rapport[] = ['ERROR', "Échec copie N° $numMatch '$type'"];
                        continue;
                    }
                    $rencontre->setPdfPath($type, $relativePath);
                }
                $stats['copied']++;
                $rapport[] = ['COPY', "N° $numMatch '$type' → {$relativePath}"];
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
     * Extrait le N° de match du nom du dossier FFBB.
     *
     * Formats supportés :
     *   - "0080_PRF_A_12_METROPOLE_AMIENOISE_BASKETBALL_ESC_LONGUEAU_AMIENS_MSBB-3" → "12"
     *   - "0002_BPRF_ALLER_A_1_ESC_TERGNIER_METROPOLE_AMIENOISE_BASKETBALL"        → "1"
     *   - "0002_BPRF_RETOUR_A_1_..."                                                → "1"
     *
     * Le format est : {code_compet}_{division}_[ALLER|RETOUR]?_A_{N}_...
     */
    private function extractNumeroMatch(string $folderName): ?string
    {
        // Pattern : "_A_NUM_" entouré
        // On capture N° entre "_A_" et l'underscore suivant
        if (preg_match('/_A_(\d+)_/', $folderName, $m)) {
            return $m[1];
        }
        return null;
    }

    private function findPdf(string $dir, string $prefix): ?string
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '_*.pdf') ?: [];
        return $files[0] ?? null;
    }
}
