<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Sport\RencontreRepository;
use App\Service\Ffbb\FfbbResumeParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [B22b 12/06/2026 — DÉSACTIVÉ 14/06/2026] Parser PDF FFBB resume_*.pdf.
 *
 * ⚠️ DÉSACTIVÉ DEPUIS LE 14/06/2026 ⚠️
 *
 * Constat : les PDFs FFBB exportés depuis Easy Stats / e-marque V2 sont
 * des rendus VISUELS SCANNÉS (image, pas de texte). smalot/pdfparser
 * retourne 0 caractère sur ces fichiers. OCR (Tesseract) non viable sur
 * OVH mutualisé (binaire absent + qualité OCR médiocre sur tableaux).
 *
 * Pivot officiel : B22b-bis. Le coach valide manuellement après match
 * via le bouton "✓ J'ai vérifié les Stats FFBB" sur la page rencontre Manager.
 *
 * La command est gardée en place :
 *   1. Pour ne pas casser une éventuelle tâche cron qui l'appellerait
 *   2. Pour servir de POC le jour où on intégrera un service OCR cloud
 *      (Google Vision API, AWS Textract, etc.) à coût raisonnable
 *
 * Si tu re-lances cette command, elle ne planera pas mais retournera 0 lignes
 * partout — c'est le comportement attendu, pas un bug.
 *
 * Usage (legacy) :
 *   php bin/console app:parse-resumes-ffbb --saison=2025-2026
 *   php bin/console app:parse-resumes-ffbb --rencontre-id=4
 */
#[AsCommand(
    name: 'app:parse-resumes-ffbb',
    description: '[DÉSACTIVÉ] Parser PDF FFBB — pivot vers validation manuelle (B22b-bis)',
)]
class ParseResumesFfbbCommand extends Command
{
    public function __construct(
        private readonly RencontreRepository $rencontreRepo,
        private readonly FfbbResumeParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL, 'Saison cible')
            ->addOption('rencontre-id', null, InputOption::VALUE_OPTIONAL, 'Une seule rencontre par ID')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force l\'exécution du parser malgré le pivot vers B22b-bis');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // [B22b-bis 14/06/2026] Garde-fou : la command est désactivée par défaut
        if (!$input->getOption('force')) {
            $io->warning([
                'Cette command est DÉSACTIVÉE depuis le 14/06/2026.',
                '',
                'Raison : les PDFs FFBB exportés depuis Easy Stats sont des rendus',
                'visuels scannés (image), incompatibles avec smalot/pdfparser.',
                '',
                'Pivot officiel : B22b-bis — Validation manuelle par le coach via',
                'le bouton "✓ J\'ai vérifié les Stats FFBB" sur la fiche rencontre',
                'Manager (/rencontres/{id}).',
                '',
                'Si tu veux quand même tester le parser (POC OCR future) :',
                '   php bin/console app:parse-resumes-ffbb --saison=2025-2026 --force',
            ]);
            return Command::SUCCESS;
        }

        $io->note('Mode --force activé : exécution du parser malgré le pivot B22b-bis.');

        $rencontres = [];
        if ($id = $input->getOption('rencontre-id')) {
            $r = $this->rencontreRepo->find((int) $id);
            if ($r) $rencontres = [$r];
        } else {
            $saison = $input->getOption('saison');
            $qb = $this->rencontreRepo->createQueryBuilder('r')
                ->where('r.resumePath IS NOT NULL');
            if ($saison) {
                $qb->andWhere('r.saison = :s')->setParameter('s', $saison);
            }
            $rencontres = $qb->getQuery()->getResult();
        }

        if (empty($rencontres)) {
            $io->warning('Aucune rencontre avec PDF resume trouvée.');
            return Command::SUCCESS;
        }

        $total = 0;
        $rapport = [];
        foreach ($rencontres as $r) {
            $count = $this->parser->parseEtPersister($r);
            $rapport[] = [$r->getId(), $r->getAdversaire(), $r->getDate()?->format('d/m/Y'), $count];
            $total += $count;
        }

        $io->table(['ID', 'Adversaire', 'Date', 'Lignes parsées'], $rapport);
        $io->success(sprintf('%d lignes joueuses extraites au total.', $total));

        return Command::SUCCESS;
    }
}
