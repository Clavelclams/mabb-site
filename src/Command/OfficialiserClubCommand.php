<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Core\ClubRepository;
use App\Service\ClubOfficialisation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renseigne le n° FFBB d'un club et recalcule son statut « officiel ».
 *
 * On ne force PAS is_officiel à la main : on passe par ClubOfficialisation,
 * qui vérifie que le numéro existe bien dans le référentiel FFBB importé
 * (table organisme_ffbb). Si le numéro n'y est pas, le club reste NON-officiel
 * (et la commande le signale) — ça évite de fabriquer un faux « officiel ».
 *
 * USAGE :
 *   php bin/console app:club:officialiser mabb HDF0080036
 *   php bin/console app:club:officialiser <slug-inconnu> XXX   → liste les slugs dispo
 */
#[AsCommand(
    name: 'app:club:officialiser',
    description: 'Renseigne le n° FFBB d\'un club et recalcule son statut officiel.',
)]
class OfficialiserClubCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepository,
        private readonly ClubOfficialisation $officialisation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug du club (ex: mabb)')
            ->addArgument('numero', InputArgument::REQUIRED, 'N° de groupement FFBB (ex: HDF0080036)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $slug   = trim((string) $input->getArgument('slug'));
        $numero = trim((string) $input->getArgument('numero'));

        $club = $this->clubRepository->findOneBy(['slug' => $slug]);
        if ($club === null) {
            $io->error("Aucun club avec le slug « $slug ».");
            $tous = $this->clubRepository->findBy([], ['nom' => 'ASC']);
            if ($tous !== []) {
                $io->writeln('Clubs existants :');
                foreach ($tous as $c) {
                    $io->writeln(sprintf('  • %s  (slug: <info>%s</info>)', $c->getNom(), $c->getSlug()));
                }
            }

            return Command::FAILURE;
        }

        $club->setNumeroFfbb($numero);
        $officiel = $this->officialisation->rafraichir($club);
        $this->em->flush();

        if ($officiel) {
            $io->success(sprintf('« %s » → n° %s : reconnu OFFICIEL FFBB.', $club->getNom(), $club->getNumeroFfbb()));
        } else {
            $io->warning(sprintf(
                '« %s » → n° %s enregistré, MAIS ce numéro n\'est pas dans le référentiel FFBB : club NON-officiel. Vérifie le numéro.',
                $club->getNom(),
                $club->getNumeroFfbb()
            ));
        }

        return Command::SUCCESS;
    }
}
