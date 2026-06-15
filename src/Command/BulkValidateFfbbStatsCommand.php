<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Rencontre;
use App\Repository\Core\UserRepository;
use App\Repository\Sport\RencontreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [B22b-bis 15/06/2026] Validation en masse des Stats FFBB.
 *
 * À utiliser pour les saisons déjà passées dont les coachs n'ont pas
 * validé manuellement chaque match. Marque toutes les rencontres avec
 * PDF resume FFBB uploadé + match passé + pas encore validées comme
 * "validées par {user}" avec une note explicite.
 *
 * À terme (V2), chaque coach validera SES matchs au fur et à mesure
 * via le bouton "✓ J'ai vérifié les Stats FFBB" sur la fiche rencontre
 * Manager. Cette commande sert uniquement à RATTRAPER l'historique.
 *
 * Usage :
 *   php bin/console app:bulk-validate-ffbb-stats --saison=2025-2026
 *   php bin/console app:bulk-validate-ffbb-stats --saison=2025-2026 --user=admin@mabb.fr
 *   php bin/console app:bulk-validate-ffbb-stats --all --user=admin@mabb.fr
 *   php bin/console app:bulk-validate-ffbb-stats --saison=2025-2026 --dry-run  (preview)
 */
#[AsCommand(
    name: 'app:bulk-validate-ffbb-stats',
    description: 'Valide en masse les Stats FFBB des rencontres avec PDF resume uploadé',
)]
class BulkValidateFfbbStatsCommand extends Command
{
    public function __construct(
        private readonly RencontreRepository $rencontreRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL, 'Saison cible (ex: 2025-2026)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Toutes les saisons (override --saison)')
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Email du user qui sera enregistré comme validateur', 'admin@mabb.fr')
            ->addOption('note', null, InputOption::VALUE_OPTIONAL, 'Commentaire de validation à apposer', 'Validation rétroactive bulk début de saison (rattrapage historique).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le récap sans rien persister');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // === Récup user validateur ===
        $userEmail = (string) $input->getOption('user');
        $user = $this->userRepo->findOneBy(['email' => $userEmail]);
        if ($user === null) {
            $io->error(sprintf('User "%s" introuvable. Vérifie l\'email avec --user=<email>.', $userEmail));
            return Command::FAILURE;
        }

        // === Filtre rencontres ===
        $qb = $this->rencontreRepo->createQueryBuilder('r')
            ->where('r.resumePath IS NOT NULL')           // a un PDF resume
            ->andWhere('r.ffbbStatsValidatedAt IS NULL')  // pas encore validé
            ->andWhere('r.date < :now')                   // match passé
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.date', 'ASC');

        if (!$input->getOption('all')) {
            $saison = $input->getOption('saison');
            if (!$saison) {
                $io->error('Spécifie --saison=YYYY-YYYY ou --all.');
                return Command::FAILURE;
            }
            $qb->andWhere('r.saison = :s')->setParameter('s', $saison);
        }

        /** @var Rencontre[] $rencontres */
        $rencontres = $qb->getQuery()->getResult();

        if (empty($rencontres)) {
            $io->success('Aucune rencontre à valider. Toutes les rencontres FFBB avec resume PDF sont déjà validées.');
            return Command::SUCCESS;
        }

        // === Récap visuel ===
        $rows = array_map(fn(Rencontre $r) => [
            $r->getId(),
            $r->getDate()?->format('d/m/Y') ?? '?',
            $r->getEquipe()?->getNom() ?? '?',
            $r->getAdversaire() ?? '?',
            $r->getSaison() ?? '?',
        ], $rencontres);

        $io->table(['ID', 'Date', 'Équipe', 'Adversaire', 'Saison'], $rows);
        $io->note(sprintf(
            '%d rencontre(s) à valider, validateur = %s %s (%s)',
            count($rencontres),
            $user->getPrenom(),
            $user->getNom(),
            $user->getEmail()
        ));

        if ($input->getOption('dry-run')) {
            $io->warning('Mode --dry-run : aucune modification persistée. Retire l\'option pour appliquer.');
            return Command::SUCCESS;
        }

        // === Confirmation interactive (sécurité) ===
        if (!$io->confirm(sprintf('Valider les %d rencontres en bulk ?', count($rencontres)), false)) {
            $io->warning('Annulé.');
            return Command::SUCCESS;
        }

        // === Application ===
        $now = new \DateTimeImmutable();
        $note = (string) $input->getOption('note');
        $count = 0;

        foreach ($rencontres as $r) {
            $r->setFfbbStatsValidatedAt($now);
            $r->setFfbbStatsValidatedBy($user);
            $r->setFfbbStatsValidationNote($note);
            $count++;
        }

        $this->em->flush();

        $io->success(sprintf(
            '✓ %d rencontre(s) validée(s) en bulk. Les joueuses verront le badge ✓ sur leur fiche match PIRB.',
            $count
        ));

        return Command::SUCCESS;
    }
}
