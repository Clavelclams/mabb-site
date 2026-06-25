<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\PlanningSeance;
use App\Repository\Core\ClubRepository;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\PlanningSeanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée les PlanningSeance récurrents 2026/27 depuis le CR de réunion du 25/06/2026.
 *
 * Usage :
 *   php bin/console app:seed-plannings-2026-27
 *   php bin/console app:seed-plannings-2026-27 --dry-run   # simule sans écrire
 *   php bin/console app:seed-plannings-2026-27 --overwrite # supprime les plannings 2026-2027 existants avant recréation
 *
 * Chaque créneau est lié à l'Equipe retrouvée via : nom LIKE '%<motclé>%' AND saison = '2026-2027'.
 * Si l'équipe n'existe pas encore en BDD, le créneau est ignoré (affiché en warning).
 * Relancer la commande après avoir créé les équipes manquantes — idempotent.
 *
 * Structure des créneaux (Source : CR Réunion 25/06/2026 — Planning Étouvie/Nord/Sud) :
 *
 * ÉTOUVIE
 *  Lundi    18:00 90' CEC (entrainement collectif collectivité)
 *  Lundi    19:30 90' U13 Région
 *  Lundi    21:00 90' Séniors Filles
 *  Mardi    18:00 90' U13 B
 *  Mardi    19:30 90' U15 R2
 *  Mardi    21:00 90' Loisirs/Cafard
 *  Mercredi 15:00 60' Micro (U6-U8)
 *  Mercredi 16:00 60' Baby (U4-U6)
 *  Mercredi 17:00 60' Mini (U9-U11)
 *  Mercredi 18:00 90' U15 R1
 *  Mercredi 19:30 90' U18 R1
 *  Mercredi 21:00 90' 3×3
 *  Jeudi    18:00 90' U13 B (2e créneau)
 *  Jeudi    19:30 90' U15 R2 (2e créneau)
 *  Jeudi    21:00 90' Loisirs (2e créneau)
 *  Vendredi 18:00 90' U15 A
 *  Vendredi 19:30 90' U18 Région
 *  Vendredi 21:00 90' Séniors
 *
 * NORD
 *  Mardi    18:30 90' U11/U13 Nord
 *  Mercredi 16:00 60' Baby Nord
 *  Mercredi 17:00 75' Mini Nord
 *  Mercredi 18:00 75' Poussine Nord
 *  Mercredi 19:15 90' U13 HDF
 *  Mercredi 20:45 95' ANBB
 *  Vendredi 19:30 90' U15/U18 Nord
 *
 * SUD
 *  Samedi   09:30 90' U7/U8/U9 Sud
 *  Samedi   11:00 60' U11 Sud
 */
#[AsCommand(
    name: 'app:seed-plannings-2026-27',
    description: 'Crée les plannings récurrents d\'entraînement 2026/27 depuis le CR du 25/06/2026.',
)]
class SeedPlannings2627Command extends Command
{
    private const SAISON = '2026-2027';

    /**
     * Définition des créneaux.
     * Format : [jourSemaine (1=Lundi), heureDebut, dureeMinutes, lieuGym, motcleEquipe, type, notes]
     *
     * motcleEquipe : chaîne partielle cherchée dans Equipe::nom (ILIKE).
     *               Mettre NULL si le créneau ne correspond pas à une équipe connue (CEC, ANBB...).
     */
    private const CRENEAUX = [
        // ── ÉTOUVIE ───────────────────────────────────────────────────────────
        [1, '18:00', 90, 'Gymnase Étouvie', null,              'Entrainement', 'CEC — créneau collectivité'],
        [1, '19:30', 90, 'Gymnase Étouvie', 'U13',             'Entrainement', 'U13 Région — Étouvie lundi'],
        [1, '21:00', 90, 'Gymnase Étouvie', 'Senior',          'Entrainement', 'Séniors Filles — Étouvie lundi'],
        [2, '18:00', 90, 'Gymnase Étouvie', 'U13 B',           'Entrainement', 'U13 B — Étouvie mardi'],
        [2, '19:30', 90, 'Gymnase Étouvie', 'U15',             'Entrainement', 'U15 R2 — Étouvie mardi'],
        [2, '21:00', 90, 'Gymnase Étouvie', 'Loisir',          'Entrainement', 'Loisirs / Cafard — Étouvie mardi'],
        [3, '15:00', 60, 'Gymnase Étouvie', 'Micro',           'Entrainement', 'Micro U6-U8 — Étouvie mercredi'],
        [3, '16:00', 60, 'Gymnase Étouvie', 'Baby',            'Entrainement', 'Baby U4-U6 — Étouvie mercredi'],
        [3, '17:00', 60, 'Gymnase Étouvie', 'Mini',            'Entrainement', 'Mini U9-U11 — Étouvie mercredi'],
        [3, '18:00', 90, 'Gymnase Étouvie', 'U15',             'Entrainement', 'U15 R1 — Étouvie mercredi'],
        [3, '19:30', 90, 'Gymnase Étouvie', 'U18',             'Entrainement', 'U18 R1 — Étouvie mercredi'],
        [3, '21:00', 90, 'Gymnase Étouvie', '3x3',             'Entrainement', '3×3 — Étouvie mercredi'],
        [4, '18:00', 90, 'Gymnase Étouvie', 'U13 B',           'Entrainement', 'U13 B — Étouvie jeudi (2e créneau)'],
        [4, '19:30', 90, 'Gymnase Étouvie', 'U15',             'Entrainement', 'U15 R2 — Étouvie jeudi (2e créneau)'],
        [4, '21:00', 90, 'Gymnase Étouvie', 'Loisir',          'Entrainement', 'Loisirs — Étouvie jeudi'],
        [5, '18:00', 90, 'Gymnase Étouvie', 'U15 A',           'Entrainement', 'U15 A — Étouvie vendredi'],
        [5, '19:30', 90, 'Gymnase Étouvie', 'U18',             'Entrainement', 'U18 Région — Étouvie vendredi'],
        [5, '21:00', 90, 'Gymnase Étouvie', 'Senior',          'Entrainement', 'Séniors — Étouvie vendredi'],

        // ── NORD ─────────────────────────────────────────────────────────────
        [2, '18:30', 90, 'Gymnase Nord',    'U11',             'Entrainement', 'U11/U13 Nord — mardi'],
        [3, '16:00', 60, 'Gymnase Nord',    'Baby',            'Entrainement', 'Baby Nord — mercredi'],
        [3, '17:00', 75, 'Gymnase Nord',    'Mini',            'Entrainement', 'Mini Nord — mercredi'],
        [3, '18:00', 75, 'Gymnase Nord',    'Poussine',        'Entrainement', 'Poussine Nord — mercredi'],
        [3, '19:15', 90, 'Gymnase Nord',    'U13',             'Entrainement', 'U13 HDF — mercredi'],
        [3, '20:45', 95, 'Gymnase Nord',    null,              'Entrainement', 'ANBB — mercredi (partenaire externe)'],
        [5, '19:30', 90, 'Gymnase Nord',    'U15',             'Entrainement', 'U15/U18 Nord — vendredi'],

        // ── SUD ──────────────────────────────────────────────────────────────
        [6, '09:30', 90, 'Gymnase Sud',     'U9',              'Entrainement', 'U7/U8/U9 Sud — samedi'],
        [6, '11:00', 60, 'Gymnase Sud',     'U11',             'Entrainement', 'U11 Sud — samedi'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly PlanningSeanceRepository $planningRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('club', null, InputOption::VALUE_OPTIONAL, 'Slug ou ID du club cible')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en BDD')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Supprime les plannings 2026-2027 existants avant recréation')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun  = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');

        $io->title('Seeding des plannings récurrents 2026/27 — CR Réunion 25/06/2026');

        // ── Trouver le club ──────────────────────────────────────────────────
        $clubOption = $input->getOption('club');
        if ($clubOption) {
            $club = is_numeric($clubOption)
                ? $this->clubRepository->find((int) $clubOption)
                : $this->clubRepository->findOneBy(['slug' => $clubOption]);
        } else {
            $clubs = $this->clubRepository->findAll();
            $club  = $clubs[0] ?? null;
        }

        if ($club === null) {
            $io->error('Aucun club trouvé.');
            return Command::FAILURE;
        }
        $io->text(sprintf('Club : <info>%s</info> | Saison : <info>%s</info>', $club->getNom(), self::SAISON));

        // ── Overwrite ────────────────────────────────────────────────────────
        if ($overwrite && !$isDryRun) {
            $existants = $this->em->createQueryBuilder()
                ->select('p')
                ->from(PlanningSeance::class, 'p')
                ->join('p.equipe', 'e')
                ->where('e.club = :club')
                ->andWhere('e.saison = :saison')
                ->setParameter('club', $club)
                ->setParameter('saison', self::SAISON)
                ->getQuery()->getResult();

            $io->text(sprintf('Suppression de %d plannings existants (--overwrite)...', count($existants)));
            foreach ($existants as $p) {
                $this->em->remove($p);
            }
            $this->em->flush();
        }

        // ── Chercher les équipes de la saison ────────────────────────────────
        $equipesClub = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Equipe::class, 'e')
            ->where('e.club = :club')
            ->andWhere('e.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', self::SAISON)
            ->getQuery()->getResult();

        /** @var array<string, Equipe> Nom normalisé → Equipe */
        $equipesIndex = [];
        foreach ($equipesClub as $e) {
            $equipesIndex[strtolower((string) $e->getNom())] = $e;
        }

        $io->text(sprintf('%d équipes trouvées pour la saison %s.', count($equipesClub), self::SAISON));

        // ── Créer les plannings ──────────────────────────────────────────────
        $cree    = 0;
        $ignore  = 0;
        $warning = 0;
        $rows    = [];

        foreach (self::CRENEAUX as $creneau) {
            [$jour, $heure, $duree, $lieu, $motcle, $type, $notes] = $creneau;

            // Chercher l'équipe par mot-clé (LIKE) si fourni
            $equipe = null;
            if ($motcle !== null) {
                $motcleNorm = strtolower($motcle);
                foreach ($equipesIndex as $nomNorm => $e) {
                    if (str_contains($nomNorm, $motcleNorm)) {
                        $equipe = $e;
                        break;
                    }
                }

                if ($equipe === null) {
                    // Essai avec DQL LIKE en base si l'index local ne suffit pas
                    $equipe = $this->em->createQueryBuilder()
                        ->select('e')
                        ->from(Equipe::class, 'e')
                        ->where('e.club = :club')
                        ->andWhere('e.saison = :saison')
                        ->andWhere('LOWER(e.nom) LIKE :motcle')
                        ->setParameter('club', $club)
                        ->setParameter('saison', self::SAISON)
                        ->setParameter('motcle', '%' . strtolower($motcle) . '%')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
                }

                if ($equipe === null) {
                    $io->warning(sprintf(
                        'Équipe "%s" introuvable en saison %s — créneau %s %s %s IGNORÉ. '
                        . 'Créer l\'équipe puis relancer la commande.',
                        $motcle, self::SAISON,
                        PlanningSeance::JOURS[$jour] ?? '?',
                        $heure, $lieu
                    ));
                    $rows[] = ['⚠', PlanningSeance::JOURS[$jour] ?? '?', $heure, $lieu, $notes, 'ÉQUIPE MANQUANTE'];
                    $warning++;
                    continue;
                }
            } else {
                // Créneau sans équipe connue (CEC, ANBB, partenaire externe)
                // On cherche ou crée une équipe "fictive" nommée selon les notes
                // → Pour l'instant on saute (pas d'équipe = PlanningSeance non créé)
                $io->text(sprintf('<comment>Créneau "%s" (%s %s %s) : pas d\'équipe MABB associée — ignoré.</comment>', $notes, PlanningSeance::JOURS[$jour] ?? '?', $heure, $lieu));
                $rows[] = ['-', PlanningSeance::JOURS[$jour] ?? '?', $heure, $lieu, $notes, 'EXTERNE (ignoré)'];
                $ignore++;
                continue;
            }

            // Vérifier doublon (même équipe + même jour + même heure)
            $doublon = $this->em->createQueryBuilder()
                ->select('p')
                ->from(PlanningSeance::class, 'p')
                ->where('p.equipe = :e')
                ->andWhere('p.jourSemaine = :jour')
                ->andWhere('p.heureDebut = :heure')
                ->setParameter('e', $equipe)
                ->setParameter('jour', $jour)
                ->setParameter('heure', $heure)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($doublon !== null) {
                $rows[] = ['↷', PlanningSeance::JOURS[$jour] ?? '?', $heure, $lieu, $equipe->getNom(), 'DOUBLON (ignoré)'];
                $ignore++;
                continue;
            }

            // Créer le PlanningSeance
            $planning = new PlanningSeance();
            $planning->setClub($club);
            $planning->setEquipe($equipe);
            $planning->setJourSemaine($jour);
            $planning->setHeureDebut($heure);
            $planning->setDureeMinutes($duree);
            $planning->setLieu($lieu);
            $planning->setType($type);
            $planning->setNotes($notes);
            $planning->setIsActive(true);

            if (!$isDryRun) {
                $this->em->persist($planning);
            }

            $rows[] = ['✓', PlanningSeance::JOURS[$jour] ?? '?', $heure, $lieu, $equipe->getNom(), sprintf('%dmin', $duree)];
            $cree++;
        }

        $io->table(['', 'Jour', 'Heure', 'Lieu', 'Équipe/Notes', 'Durée/Statut'], $rows);

        if (!$isDryRun) {
            $this->em->flush();
        }

        $io->section('Résultat');
        $io->text([
            sprintf('✅ Créés    : %d plannings', $cree),
            sprintf('↷  Ignorés  : %d (doublons ou créneaux externes)', $ignore),
            sprintf('⚠  Manquants: %d équipes non trouvées (relancer après création)', $warning),
        ]);

        if ($warning > 0) {
            $io->note(
                "Pour les créneaux ⚠ : créer les équipes manquantes dans Manager "
                . "(Équipes → Nouvelle équipe, saison = " . self::SAISON . "), "
                . "puis relancer : php bin/console app:seed-plannings-2026-27"
            );
        }

        if (!$isDryRun && $cree > 0) {
            $io->success(sprintf(
                '%d plannings créés ! Lance maintenant la génération des séances : '
                . 'depuis Manager → Équipes → Générer les séances.',
                $cree
            ));
        } elseif ($isDryRun) {
            $io->warning('--dry-run actif : rien écrit en BDD.');
        }

        return Command::SUCCESS;
    }
}
