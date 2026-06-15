<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Rencontre;
use App\Repository\Sport\RencontreRepository;
use App\Service\Ffbb\FfbbResumeOcrParser;
use App\Service\Ffbb\GoogleVisionOcrService;
use App\Service\RencontrePdfUploader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [B-FFBB-OCR 15/06/2026] Pipeline OCR Google Vision + parser FFBB pour rattraper
 * toutes les rencontres d'une saison qui ont un PDF resume FFBB uploadé.
 *
 * Workflow :
 *   1. Trouve les rencontres de la saison cible avec resume_path != null
 *   2. Pour chaque rencontre :
 *      a. Lit le PDF physique
 *      b. Appel Vision API → texte OCR
 *      c. Parse texte → EvaluationMatch
 *      d. Persiste en base
 *   3. Affiche récap visuel + warnings ligne par ligne
 *
 * Usage :
 *   php bin/console app:process-ffbb-resumes --saison=2025-2026
 *   php bin/console app:process-ffbb-resumes --rencontre-id=5  (un seul match)
 *   php bin/console app:process-ffbb-resumes --saison=2025-2026 --dry-run
 *
 * Coût estimé : ~0,0015 \$ par PDF (Document Text Detection).
 * Pour 14 matchs PRF saison 2025-2026 = ~0,021 \$ total (~2 centimes d'euro).
 */
#[AsCommand(
    name: 'app:process-ffbb-resumes',
    description: 'Pipeline OCR Vision + parse les PDFs resume FFBB pour créer les EvaluationMatch',
)]
class ProcessFfbbResumesOcrCommand extends Command
{
    public function __construct(
        private readonly RencontreRepository $rencontreRepo,
        private readonly GoogleVisionOcrService $ocrService,
        private readonly FfbbResumeOcrParser $parser,
        private readonly RencontrePdfUploader $pdfUploader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL, 'Saison cible (ex: 2025-2026)')
            ->addOption('rencontre-id', null, InputOption::VALUE_OPTIONAL, 'Une seule rencontre par ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait fait sans appeler Vision ni persister')
            ->addOption('skip-existing', null, InputOption::VALUE_NONE, 'Skip les rencontres avec EvaluationMatch déjà saisie (rapide pour rattrapage)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // === 1. Sélectionner les rencontres à traiter ===
        $rencontres = $this->getRencontresToProcess($input, $io);
        if ($rencontres === null) {
            return Command::FAILURE;
        }

        if (empty($rencontres)) {
            $io->success('Aucune rencontre à traiter.');
            return Command::SUCCESS;
        }

        $io->note(sprintf(
            '%d rencontre(s) trouvée(s) avec PDF resume. Coût estimé : ~%s €',
            count($rencontres),
            number_format(count($rencontres) * 0.0015 * 0.92, 4), // $ → €
        ));

        if ($input->getOption('dry-run')) {
            $io->warning('Mode --dry-run : aucun appel API Vision, aucune persistance.');
            foreach ($rencontres as $r) {
                $io->writeln(sprintf(
                    '   • Rencontre #%d - %s vs %s (%s) - resume: %s',
                    $r->getId(),
                    $r->getEquipe()?->getNom() ?? '?',
                    $r->getAdversaire() ?? '?',
                    $r->getDate()?->format('d/m/Y') ?? '?',
                    $r->getResumePath() ? basename($r->getResumePath()) : 'NULL',
                ));
            }
            return Command::SUCCESS;
        }

        // === 2. Confirmation interactive ===
        if (!$io->confirm(sprintf('Lancer le pipeline OCR sur %d rencontre(s) ?', count($rencontres)), false)) {
            $io->warning('Annulé.');
            return Command::SUCCESS;
        }

        // === 3. Traitement de chaque rencontre ===
        $totalCreees = 0;
        $totalMajees = 0;
        $totalWarnings = 0;
        $totalErreurs = 0;

        foreach ($rencontres as $rencontre) {
            $io->section(sprintf(
                'Match #%d : %s vs %s — %s',
                $rencontre->getId(),
                $rencontre->getEquipe()?->getNom() ?? '?',
                $rencontre->getAdversaire() ?? '?',
                $rencontre->getDate()?->format('d/m/Y') ?? '?',
            ));

            $pdfAbsolutePath = $this->pdfUploader->getAbsolutePath($rencontre, 'resume');
            if ($pdfAbsolutePath === null) {
                $io->warning('PDF resume introuvable physiquement. Skip.');
                $totalErreurs++;
                continue;
            }

            try {
                $io->writeln(' ⏳ Appel Vision API...');
                $ocrText = $this->ocrService->extractTextFromPdf($pdfAbsolutePath);
                $io->writeln(sprintf(' ✓ %d caractères extraits', strlen($ocrText)));

                $io->writeln(' 🔍 Parsing texte OCR → EvaluationMatch...');
                $parseResult = $this->parser->parseEtPersister($rencontre, $ocrText);

                $io->writeln(sprintf(
                    ' ✓ Parsées : %d | Matchées : %d | Créées : %d | Maj : %d | Warnings : %d',
                    $parseResult['joueuses_parsees'],
                    $parseResult['joueuses_matchees'],
                    $parseResult['evals_creees'],
                    $parseResult['evals_majees'],
                    count($parseResult['warnings']),
                ));

                $totalCreees += $parseResult['evals_creees'];
                $totalMajees += $parseResult['evals_majees'];
                $totalWarnings += count($parseResult['warnings']);

                foreach ($parseResult['warnings'] as $warning) {
                    $io->writeln('   ⚠️  ' . $warning);
                }
            } catch (\Throwable $e) {
                $io->error('Échec : ' . $e->getMessage());
                $totalErreurs++;
            }
        }

        // === 4. Récap final ===
        $io->success(sprintf(
            'Pipeline OCR terminé. Total : %d créées | %d mises à jour | %d warnings | %d erreurs',
            $totalCreees,
            $totalMajees,
            $totalWarnings,
            $totalErreurs,
        ));

        $io->note('Les joueuses verront leurs stats individuelles dans PIRB sur /stats/match/{id}.');

        return Command::SUCCESS;
    }

    /**
     * Récupère la liste des rencontres à traiter selon les options.
     * @return Rencontre[]|null null si erreur de paramètres
     */
    private function getRencontresToProcess(InputInterface $input, SymfonyStyle $io): ?array
    {
        $rencontreId = $input->getOption('rencontre-id');
        if ($rencontreId !== null) {
            $r = $this->rencontreRepo->find((int) $rencontreId);
            if (!$r) {
                $io->error("Rencontre ID {$rencontreId} introuvable.");
                return null;
            }
            if (!$r->getResumePath()) {
                $io->error("Rencontre ID {$rencontreId} n'a pas de PDF resume.");
                return null;
            }
            return [$r];
        }

        $saison = $input->getOption('saison');
        if (!$saison) {
            $io->error('Précise --saison=YYYY-YYYY ou --rencontre-id=X.');
            return null;
        }

        return $this->rencontreRepo->createQueryBuilder('r')
            ->where('r.saison = :s')
            ->andWhere('r.resumePath IS NOT NULL')
            ->setParameter('s', $saison)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
