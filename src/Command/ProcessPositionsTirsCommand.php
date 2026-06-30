<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Sport\RencontreRepository;
use App\Service\Ffbb\FfbbPositionTirParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [B22c 30/06/2026] Commande d'import des positions de tirs FFBB.
 *
 * Lit les PDF positiontir_*.pdf stockés dans public/uploads/rencontres/
 * via le script Python bin/ffbb_parse_positions.py (PyMuPDF + Tesseract),
 * et peuple la table tir_ffbb avec les coordonnées normalisées [0–100].
 *
 * Usage :
 *   php bin/console app:process-positions-tirs --saison=2025-2026
 *   php bin/console app:process-positions-tirs --rencontre-id=42
 *   php bin/console app:process-positions-tirs --saison=2025-2026 --dry-run
 *
 * Prérequis serveur :
 *   python3 + pip install pymupdf pytesseract pillow + tesseract (eng data)
 *
 * Idempotent : re-parser une rencontre supprime les anciens tirs FFBB avant insertion.
 */
#[AsCommand(
    name: 'app:process-positions-tirs',
    description: 'Importe les positions de tirs depuis les PDF positiontir FFBB',
)]
class ProcessPositionsTirsCommand extends Command
{
    public function __construct(
        private readonly RencontreRepository $rencontreRepo,
        private readonly FfbbPositionTirParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saison', null, InputOption::VALUE_REQUIRED, 'Ex: 2025-2026')
            ->addOption('rencontre-id', null, InputOption::VALUE_REQUIRED, 'ID rencontre unique')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en BDD')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Réimporte même si tirs déjà présents');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Mode DRY-RUN — aucune écriture en BDD');
        }

        // ── Sélection des rencontres ──────────────────────────────────────────
        $rencontreId = $input->getOption('rencontre-id');
        $saison      = $input->getOption('saison');

        if ($rencontreId !== null) {
            $rencontre = $this->rencontreRepo->find((int) $rencontreId);
            if ($rencontre === null) {
                $io->error("Rencontre #{$rencontreId} introuvable.");
                return Command::FAILURE;
            }
            $rencontres = [$rencontre];
        } elseif ($saison !== null) {
            $rencontres = $this->rencontreRepo->findBySaisonWithPositionsPdf($saison);
            if (empty($rencontres)) {
                $io->warning("Aucune rencontre avec PDF positions_tirs pour la saison {$saison}.");
                return Command::SUCCESS;
            }
        } else {
            $io->error('Préciser --saison=XXXX-XXXX ou --rencontre-id=N');
            return Command::FAILURE;
        }

        $io->title(sprintf('Import positions tirs — %d rencontre(s)', count($rencontres)));

        // ── Traitement ────────────────────────────────────────────────────────
        $totalInseres = 0;
        $totalSkip    = 0;
        // $force réservé pour future gestion "skip si déjà importé"
        // $force = (bool) $input->getOption('force');

        foreach ($rencontres as $r) {
            $label = sprintf(
                'vs %s (%s)',
                $r->getAdversaire() ?? '?',
                $r->getDate()?->format('d/m/Y') ?? '?'
            );

            if ($r->getPositionsTirsPath() === null) {
                $io->text("  ⊘ [#{$r->getId()}] {$label} — pas de PDF positions tirs");
                $totalSkip++;
                continue;
            }

            $io->text("  → [#{$r->getId()}] {$label}");

            $n = $this->parser->parseEtPersister($r, $dryRun);

            if ($n > 0) {
                $io->text("    ✓ {$n} tirs insérés" . ($dryRun ? ' (dry-run)' : ''));
                $totalInseres += $n;
            } else {
                $io->text('    ⚠ 0 tirs — Python indisponible ou PDF vide');
                $totalSkip++;
            }
        }

        // ── Résumé ────────────────────────────────────────────────────────────
        $io->newLine();
        $io->success(sprintf(
            '%d tirs insérés, %d rencontres skippées%s',
            $totalInseres,
            $totalSkip,
            $dryRun ? ' (DRY-RUN)' : '',
        ));

        return Command::SUCCESS;
    }
}
