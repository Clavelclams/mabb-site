<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Equipe;
use App\Service\Import\ImportRencontresService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import des rencontres FFBB depuis un xlsx exporté depuis FBI / e-marque.
 *
 * Depuis 2026-07, la logique de parsing vit dans App\Service\Import\ImportRencontresService
 * (partagée avec l'UI web du Manager). Cette commande ne fait plus que :
 *   - charger le Club (--club-id) et trouver/créer l'Equipe (--equipe-*) ;
 *   - déléguer le parsing au service (le --club-mabb-pattern devient l'indice
 *     de détection de « notre équipe ») ;
 *   - afficher le bilan.
 *
 * STRUCTURE xlsx attendue (en-tête ligne 1) :
 *   Division | N° de match | Equipe 1 | Equipe 2 | Date | Heure | Salle |
 *   e-Marque V2 | Score 1 | Forfait 1 | Score 2 | Forfait 2
 *
 * USAGE :
 *   php bin/console app:import-rencontres-from-xlsx "fichier.xlsx" \
 *       --equipe-nom="U18 R" --equipe-categorie="U18" --equipe-niveau="Régional"
 *   php bin/console app:import-rencontres-from-xlsx ... --dry-run
 */
#[AsCommand(
    name: 'app:import-rencontres-from-xlsx',
    description: 'Importe les rencontres FFBB d\'une équipe depuis un xlsx exporté FBI/e-marque.'
)]
final class ImportRencontresFromXlsxCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportRencontresService $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('xlsx', InputArgument::REQUIRED, 'Chemin du fichier xlsx FFBB.')
            ->addOption('equipe-nom', null, InputOption::VALUE_REQUIRED,
                'Nom interne de l\'équipe (ex: "U18 Régional 2025-2026").')
            ->addOption('equipe-categorie', null, InputOption::VALUE_REQUIRED,
                'Catégorie FFBB (U7, U9, U11, U13, U15, U17, U18, "Senior F"...).')
            ->addOption('equipe-niveau', null, InputOption::VALUE_OPTIONAL,
                'Niveau libre (ex: "Régional"). Stocké dans Equipe.niveau.')
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL,
                'Saison ISO (ex: 2025-2026).', '2025-2026')
            ->addOption('club-mabb-pattern', null, InputOption::VALUE_OPTIONAL,
                'Indice (sous-chaîne) pour identifier « notre » équipe dans le xlsx. Vide = détection auto par fréquence.',
                'METROPOLE AMIENOISE')
            ->addOption('club-id', null, InputOption::VALUE_OPTIONAL,
                'ID du Club en base. Default = 1.', '1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Simule sans écrire en base.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $xlsxPath        = (string) $input->getArgument('xlsx');
        $equipeNom       = $input->getOption('equipe-nom');
        $equipeCategorie = $input->getOption('equipe-categorie');
        $equipeNiveau    = $input->getOption('equipe-niveau');
        $saison          = (string) $input->getOption('saison');
        $hint            = (string) $input->getOption('club-mabb-pattern');
        $clubId          = (int) $input->getOption('club-id');
        $dryRun          = (bool) $input->getOption('dry-run');

        if (!is_file($xlsxPath)) {
            $io->error("Fichier xlsx introuvable : $xlsxPath");

            return Command::FAILURE;
        }
        if (!$equipeNom || !$equipeCategorie) {
            $io->error('Options --equipe-nom et --equipe-categorie obligatoires.');

            return Command::FAILURE;
        }
        if (!in_array($equipeCategorie, Equipe::CATEGORIES, true)) {
            $io->error(sprintf('Catégorie invalide : %s. Attendu parmi : %s', $equipeCategorie, implode(', ', Equipe::CATEGORIES)));

            return Command::FAILURE;
        }

        $io->title('Import rencontres FFBB');

        // Club
        $club = $this->em->getRepository(\App\Entity\Core\Club::class)->find($clubId);
        if (!$club) {
            $io->error("Club id=$clubId introuvable.");

            return Command::FAILURE;
        }

        // Équipe : trouver ou créer
        $equipeRepo = $this->em->getRepository(Equipe::class);
        $equipe = $equipeRepo->findOneBy(['club' => $club, 'nom' => $equipeNom, 'saison' => $saison]);
        if ($equipe === null) {
            $equipe = (new Equipe())
                ->setClub($club)
                ->setNom($equipeNom)
                ->setCategorie($equipeCategorie)
                ->setSaison($saison);
            if ($equipeNiveau) {
                $equipe->setNiveau($equipeNiveau);
            }
            if (!$dryRun) {
                $this->em->persist($equipe);
                $this->em->flush();
                $io->note("Équipe « $equipeNom » créée (id={$equipe->getId()}).");
            } else {
                $io->note("[DRY-RUN] Équipe « $equipeNom » serait créée.");
            }
        } else {
            $io->note("Équipe « $equipeNom » réutilisée (id={$equipe->getId()}).");
        }

        // Parsing délégué au service
        try {
            $r = $this->importer->importFromFile($xlsxPath, $club, $equipe, $saison, $dryRun, $hint ?: null);
        } catch (\Throwable $e) {
            $io->error('Erreur lecture/parsing : ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->section('Équipe détectée : ' . ($r['equipe_detectee'] ?? '(aucune)'));

        if ($dryRun) {
            foreach ($r['apercu'] as $m) {
                $io->writeln(sprintf('  [DRY-RUN] %s vs %s — %s — n°%s (%s) — %s',
                    $m['domicile'] ? 'DOM.' : 'EXT.', $m['adversaire'], $m['date'], $m['numero'], $m['division'] ?? '?', $m['score']));
            }
        }
        foreach ($r['erreurs'] as $err) {
            $io->warning($err);
        }

        $io->table(['Métrique', 'Valeur'], [
            ['Lignes lues', $r['stats']['lignes_lues']],
            ['Exempt (skip)', $r['stats']['exempt']],
            ['Pas notre équipe (skip)', $r['stats']['pas_equipe']],
            ['Déjà en base (skip)', $r['stats']['deja_en_base']],
            ['Rencontres créées', $r['stats']['creees']],
            ['Erreurs', $r['stats']['erreurs']],
        ]);

        if ($dryRun) {
            $io->info('DRY-RUN : aucune écriture. Relance sans --dry-run pour appliquer.');
        } else {
            $io->success("Import terminé : {$r['stats']['creees']} rencontres créées.");
        }

        return Command::SUCCESS;
    }
}
