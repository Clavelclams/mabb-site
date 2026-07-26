<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\SessionStatsLive;
use App\Repository\Sport\PresenceTerrainRepository;
use App\Service\Stats\ActionMatchAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [V2.4o] Réparation ponctuelle : régénère les minutes jouées des matchs
 * DÉJÀ promus en session officielle.
 *
 * POURQUOI :
 *   Jusqu'au 26/07/2026, ActionMatchAggregator calculait les minutes avec
 *   l'approximation « quarts où la joueuse a une action × 10 min ». Les
 *   EvaluationMatch déjà générées portent ces valeurs fausses — et rien ne
 *   les recalcule spontanément (la régénération n'a lieu qu'à la promotion).
 *
 * CE QUE FAIT LA COMMANDE, pour chaque rencontre ayant une session OFFICIELLE :
 *   1. Clôture les présences terrain restées ouvertes (le cinq du buzzer),
 *      à la fin estimée du match — même logique que la promotion V2.4o ;
 *   2. Régénère toutes les EvaluationMatch via l'agrégateur corrigé
 *      (minutes réelles + titulaire réel + compteurs inchangés).
 *
 * Les matchs SANS présences terrain retombent sur l'approximation par
 * quarts (avec la vraie durée de période) — pas pire qu'avant, souvent mieux.
 * Les EvaluationMatch d'import FFBB / saisie manuelle (sans ActionMatch)
 * ne sont PAS touchées : l'agrégateur ne régénère que les joueuses qui ont
 * des actions live.
 *
 * IDEMPOTENT : relançable sans risque, le recalcul écrase avec les mêmes valeurs.
 *
 * Usage :
 *   php bin/console app:stats:regenerer-minutes --dry-run   # montre sans écrire
 *   php bin/console app:stats:regenerer-minutes             # répare
 */
#[AsCommand(
    name: 'app:stats:regenerer-minutes',
    description: 'Régénère les minutes jouées (et titulaires) des matchs déjà promus, depuis les présences terrain.',
)]
final class RegenererMinutesJoueesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PresenceTerrainRepository $presenceTerrainRepository,
        private readonly ActionMatchAggregator $aggregator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule : affiche ce qui serait fait, n\'écrit rien.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Mode simulation — aucune écriture.');
        }

        // Toutes les sessions officielles = tous les matchs dont les stats
        // live ont été validées. C'est EUX qui portent des minutes fausses.
        /** @var SessionStatsLive[] $sessions */
        $sessions = $this->em->getRepository(SessionStatsLive::class)
            ->findBy(['statut' => SessionStatsLive::STATUT_OFFICIELLE]);

        if ($sessions === []) {
            $io->success('Aucune session officielle — rien à réparer.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('%d rencontre(s) avec session officielle à retraiter', count($sessions)));

        $totalCloturees = 0;
        $totalEvals = 0;

        foreach ($sessions as $session) {
            $rencontre = $session->getRencontre();
            if ($rencontre === null) {
                continue;
            }

            $libelle = sprintf(
                '#%d %s vs %s (%s)',
                $rencontre->getId(),
                $rencontre->getEquipe()?->getNom() ?? 'Interne',
                $rencontre->getAdversaire() ?? 'A/B',
                $rencontre->getDate()?->format('d/m/Y') ?? 'sans date'
            );

            // 1. Clôture des présences ouvertes (même logique que la promotion)
            $ouvertes = $this->presenceTerrainRepository->findOuvertes($rencontre, $session);
            if ($ouvertes !== []) {
                $finEstimee = max(
                    $rencontre->getDureeTotaleSecondes(),
                    $this->presenceTerrainRepository->maxSecondesSortie($rencontre, $session)
                );
                foreach ($ouvertes as $p) {
                    if (!$dryRun) {
                        $p->setSecondesSortie(max($finEstimee, $p->getSecondesEntree()));
                    }
                }
                $totalCloturees += count($ouvertes);
                $io->text(sprintf('  %s — %d présence(s) clôturée(s) à %d s', $libelle, count($ouvertes), $finEstimee));
            }

            // 2. Régénération des EvaluationMatch (minutes + titulaire + compteurs)
            if (!$dryRun) {
                $this->em->flush(); // les clôtures d'abord : l'agrégateur les lit
                $evals = $this->aggregator->regenererToutesPourRencontre($rencontre);
                $totalEvals += count($evals);
                $io->text(sprintf('  %s — %d éval(s) régénérée(s)', $libelle, count($evals)));
            } else {
                $io->text(sprintf('  %s — évals à régénérer', $libelle));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s : %d présence(s) clôturée(s), %d évaluation(s) régénérée(s) sur %d rencontre(s).',
            $dryRun ? 'Simulation terminée' : 'Réparation terminée',
            $totalCloturees,
            $totalEvals,
            count($sessions)
        ));

        return Command::SUCCESS;
    }
}
