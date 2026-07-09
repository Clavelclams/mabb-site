<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Core\OrganismeFfbb;
use App\Repository\Core\OrganismeFfbbRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import du référentiel officiel FFBB depuis l'export « Rechercher un organisme ».
 *
 * STRUCTURE xlsx ATTENDUE (en-tête ligne 1) :
 *   N° groupement | Nom de la structure | Type de la structure
 *   ex. HDF0080036 | METROPOLE AMIENOISE BASKETBALL | Club
 *
 * Sert à décider si un club de la plateforme est « officiel » (son numéro FFBB
 * doit exister dans cette table). Idempotent : upsert par numéro → on peut
 * relancer sur plusieurs exports (ex. rechercherOrganisme.xlsx + celui (1)).
 *
 * USAGE :
 *   php bin/console app:import-ffbb-organismes "chemin/rechercherOrganisme.xlsx"
 *   php bin/console app:import-ffbb-organismes "..." --dry-run   (simulation)
 */
#[AsCommand(
    name: 'app:import-ffbb-organismes',
    description: 'Importe le référentiel officiel FFBB (export « Rechercher un organisme » .xlsx).',
)]
class ImportFfbbOrganismesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganismeFfbbRepository $repo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Chemin du .xlsx export FFBB')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fichier = (string) $input->getArgument('fichier');
        $dryRun  = (bool) $input->getOption('dry-run');

        if (!is_file($fichier)) {
            $io->error("Fichier introuvable : $fichier");
            return Command::FAILURE;
        }

        $io->title('Import référentiel FFBB' . ($dryRun ? ' (DRY-RUN)' : ''));

        $sheet = IOFactory::load($fichier)->getActiveSheet();
        $rows  = $sheet->toArray(null, true, false, false);

        $crees = 0; $maj = 0; $ignores = 0; $ligne = 0;
        // Cache des numéros déjà traités dans CE fichier (évite les doublons internes).
        $vusDansFichier = [];

        foreach ($rows as $i => $row) {
            $ligne++;
            if ($i === 0) { continue; } // en-tête

            $numero = isset($row[0]) ? strtoupper(trim((string) $row[0])) : '';
            $nom    = isset($row[1]) ? trim((string) $row[1]) : '';
            $type   = isset($row[2]) ? trim((string) $row[2]) : null;

            // Ligne inexploitable (numéro ou nom manquant, ou reste d'en-tête)
            if ($numero === '' || $nom === '' || !preg_match('/^[A-Z]{2,4}\d{5,}$/', $numero)) {
                $ignores++;
                continue;
            }
            if (isset($vusDansFichier[$numero])) { $ignores++; continue; }
            $vusDansFichier[$numero] = true;

            $orga = $this->repo->findOneByNumero($numero);
            if ($orga === null) {
                $orga = (new OrganismeFfbb())->setNumero($numero);
                $crees++;
            } else {
                $maj++;
            }
            $orga->setNom($nom)->setType($type !== '' ? $type : null);

            if (!$dryRun) {
                $this->em->persist($orga);
                // Flush par lots pour ne pas exploser la mémoire sur ~7000 lignes.
                if (($crees + $maj) % 500 === 0) {
                    $this->em->flush();
                    $this->em->clear(OrganismeFfbb::class);
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s — %d créés, %d mis à jour, %d ignorés (sur %d lignes).',
            $dryRun ? 'Simulation terminée' : 'Import terminé',
            $crees, $maj, $ignores, $ligne
        ));
        if ($dryRun) {
            $io->note('DRY-RUN : rien écrit. Relance sans --dry-run pour importer.');
        }

        return Command::SUCCESS;
    }
}
