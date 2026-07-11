<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\DossierLicence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Synchronise en une passe les infos entre la FICHE joueuse et le DOSSIER
 * licence du secrétariat : n° de licence, téléphone, date de naissance.
 *
 * RÈGLE : on ne remplit que les VIDES, dans les deux sens. Jamais d'écrasement.
 *   - dossier vide + fiche renseignée → on copie vers le dossier
 *   - fiche vide + dossier renseigné  → on copie vers la fiche
 * Un dossier sans fiche joueuse liée est ignoré (aucune source).
 *
 * Idempotent : relançable sans risque. Simulation par défaut.
 *
 * USAGE :
 *   php bin/console app:secretariat:sync-fiches            (simulation, n'écrit rien)
 *   php bin/console app:secretariat:sync-fiches --execute  (applique en base)
 */
#[AsCommand(
    name: 'app:secretariat:sync-fiches',
    description: 'Synchronise licence / téléphone / date de naissance entre fiche joueuse et dossier (remplit les vides, sans écraser).'
)]
final class SyncSecretariatFichesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Écrit réellement en base (sinon simulation).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');

        $io->title('Synchro fiche ↔ dossier' . ($execute ? '' : ' (SIMULATION)'));

        $dossiers = $this->em->getRepository(DossierLicence::class)->findAll();

        $stats = [
            'licence_vers_dossier' => 0, 'licence_vers_fiche' => 0,
            'tel_vers_dossier'     => 0, 'tel_vers_fiche'     => 0,
            'ddn_vers_dossier'     => 0, 'ddn_vers_fiche'     => 0,
            'sans_fiche'           => 0,
        ];

        foreach ($dossiers as $d) {
            $j = $d->getJoueur();
            if ($j === null) {
                $stats['sans_fiche']++;
                continue;
            }

            // N° de licence
            $dl = trim((string) $d->getNumeroLicence());
            $jl = trim((string) $j->getLicence());
            if ($dl === '' && $jl !== '') {
                $d->setNumeroLicence($jl);
                $stats['licence_vers_dossier']++;
            } elseif ($jl === '' && $dl !== '') {
                $j->setLicence($dl);
                $stats['licence_vers_fiche']++;
            }

            // Téléphone
            $dt = trim((string) $d->getTelephone());
            $jt = trim((string) $j->getTelephone());
            if ($dt === '' && $jt !== '') {
                $d->setTelephone($jt);
                $stats['tel_vers_dossier']++;
            } elseif ($jt === '' && $dt !== '') {
                $j->setTelephone($dt);
                $stats['tel_vers_fiche']++;
            }

            // Date de naissance
            if ($d->getDateNaissance() === null && $j->getDateNaissance() !== null) {
                $d->setDateNaissance($j->getDateNaissance());
                $stats['ddn_vers_dossier']++;
            } elseif ($j->getDateNaissance() === null && $d->getDateNaissance() !== null) {
                $j->setDateNaissance($d->getDateNaissance());
                $stats['ddn_vers_fiche']++;
            }
        }

        if ($execute) {
            $this->em->flush();
        }

        $io->table(['Action', 'Nombre'], [
            ['Licence  fiche → dossier', $stats['licence_vers_dossier']],
            ['Licence  dossier → fiche', $stats['licence_vers_fiche']],
            ['Tél      fiche → dossier', $stats['tel_vers_dossier']],
            ['Tél      dossier → fiche', $stats['tel_vers_fiche']],
            ['Naiss.   fiche → dossier', $stats['ddn_vers_dossier']],
            ['Naiss.   dossier → fiche', $stats['ddn_vers_fiche']],
            ['Dossiers sans fiche liée (ignorés)', $stats['sans_fiche']],
        ]);

        if ($execute) {
            $io->success('Synchro appliquée en base.');
        } else {
            $io->note('SIMULATION : rien écrit. Relance avec --execute pour appliquer.');
        }

        return Command::SUCCESS;
    }
}
