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
 * [B22b 12/06/2026] Parse tous les PDFs resume_*.pdf des rencontres et
 * persiste les stats individuelles dans evaluation_ffbb.
 *
 * Usage :
 *   php bin/console app:parse-resumes-ffbb --saison=2025-2026
 *   php bin/console app:parse-resumes-ffbb --rencontre-id=4
 */
#[AsCommand(
    name: 'app:parse-resumes-ffbb',
    description: 'Parse les PDFs resume FFBB et extrait les stats individuelles',
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
            ->addOption('rencontre-id', null, InputOption::VALUE_OPTIONAL, 'Une seule rencontre par ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

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
