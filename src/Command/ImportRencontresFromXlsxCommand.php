<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
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
 * Import des rencontres FFBB depuis un xlsx exporté depuis FBI / e-marque.
 *
 * STRUCTURE xlsx FFBB ATTENDUE (en-tête ligne 1) :
 *   Division | N° de match | Equipe 1 | Equipe 2 | Date | Heure | Salle |
 *   e-Marque V2 | Score 1 | Forfait 1 | Score 2 | Forfait 2
 *
 * COMPORTEMENT :
 *   - Crée l'Equipe (--equipe-nom) si absente (ou réutilise si déjà présente
 *     par (club, nom, saison))
 *   - Parcourt chaque ligne du xlsx
 *   - Skip les lignes où Equipe 1 OU Equipe 2 = "Exempt" (FFBB met ça pour
 *     les journées sans adversaire)
 *   - Détecte le côté MABB (LOCAUX ou VISITEURS) via --club-mabb-pattern
 *   - Crée la Rencontre si pas déjà existante (idempotence : club_id +
 *     equipe_id + numero_match + saison)
 *
 * USAGE TYPE :
 *   php bin/console app:import-rencontres-from-xlsx \
 *       "ressource/rencontre u18/rechercherRencontre (3).xlsx" \
 *       --equipe-nom="U18 R" \
 *       --equipe-categorie="U18" \
 *       --equipe-niveau="Régional"
 *
 *   php bin/console app:import-rencontres-from-xlsx ... --dry-run
 *     → simule sans écrire en base (utile pour valider avant de lancer)
 *
 * IDEMPOTENCE :
 *   Lancer 2 fois la commande sur le même xlsx ne crée pas de doublons. Les
 *   rencontres déjà en base sont skippées (basé sur la contrainte UNIQUE
 *   uniq_R_club_equipe_numero_saison).
 */
#[AsCommand(
    name: 'app:import-rencontres-from-xlsx',
    description: 'Importe les rencontres FFBB d\'une équipe depuis un xlsx exporté FBI/e-marque.'
)]
final class ImportRencontresFromXlsxCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('xlsx', InputArgument::REQUIRED, 'Chemin du fichier xlsx FFBB.')
            ->addOption('equipe-nom', null, InputOption::VALUE_REQUIRED,
                'Nom interne MABB de l\'équipe (ex: "U18 Régional 2025-2026").')
            ->addOption('equipe-categorie', null, InputOption::VALUE_REQUIRED,
                'Catégorie FFBB (U7, U9, U11, U13, U15, U17, U18, "Senior F"...).')
            ->addOption('equipe-niveau', null, InputOption::VALUE_OPTIONAL,
                'Niveau libre (ex: "Régional", "Départemental", "Phase retour"). Stocké dans Equipe.niveau.')
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL,
                'Saison ISO (ex: 2025-2026).', '2025-2026')
            ->addOption('club-mabb-pattern', null, InputOption::VALUE_OPTIONAL,
                'Pattern (case-insensitive) pour identifier MABB dans le nom d\'équipe FFBB.',
                'METROPOLE AMIENOISE')
            ->addOption('club-id', null, InputOption::VALUE_OPTIONAL,
                'ID du Club MABB en base (pour environnements multi-clubs). Default = 1.', '1')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Simule sans écrire en base. Affiche ce qui serait créé.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $xlsxPath = $input->getArgument('xlsx');
        $equipeNom = $input->getOption('equipe-nom');
        $equipeCategorie = $input->getOption('equipe-categorie');
        $equipeNiveau = $input->getOption('equipe-niveau');
        $saison = $input->getOption('saison');
        $mabbPattern = $input->getOption('club-mabb-pattern');
        $clubId = (int) $input->getOption('club-id');
        $dryRun = (bool) $input->getOption('dry-run');

        // === Garde-fous ===
        if (!is_file($xlsxPath)) {
            $io->error("Fichier xlsx introuvable : $xlsxPath");
            return Command::FAILURE;
        }
        if (!$equipeNom || !$equipeCategorie) {
            $io->error("Options --equipe-nom et --equipe-categorie obligatoires.");
            return Command::FAILURE;
        }
        if (!in_array($equipeCategorie, Equipe::CATEGORIES, true)) {
            $io->error(sprintf(
                "Catégorie invalide : %s. Attendu parmi : %s",
                $equipeCategorie,
                implode(', ', Equipe::CATEGORIES)
            ));
            return Command::FAILURE;
        }

        $io->title("Import rencontres FFBB → MABB");
        $io->table(['Paramètre', 'Valeur'], [
            ['Fichier', basename($xlsxPath)],
            ['Équipe', $equipeNom],
            ['Catégorie', $equipeCategorie],
            ['Niveau', $equipeNiveau ?: '(vide)'],
            ['Saison', $saison],
            ['Pattern MABB', $mabbPattern],
            ['Mode', $dryRun ? 'DRY-RUN (simulation)' : 'WRITE (prod)'],
        ]);

        // === 1. Récupérer le Club ===
        $club = $this->em->getRepository(\App\Entity\Core\Club::class)->find($clubId);
        if (!$club) {
            $io->error("Club id=$clubId introuvable.");
            return Command::FAILURE;
        }
        $io->section("Club : " . $club->getNom());

        // === 2. Trouver ou créer l'Equipe ===
        $equipeRepo = $this->em->getRepository(Equipe::class);
        $equipe = $equipeRepo->findOneBy([
            'club'   => $club,
            'nom'    => $equipeNom,
            'saison' => $saison,
        ]);

        if ($equipe === null) {
            $io->note("Équipe \"$equipeNom\" inexistante → création.");
            $equipe = new Equipe();
            $equipe->setClub($club);
            $equipe->setNom($equipeNom);
            $equipe->setCategorie($equipeCategorie);
            $equipe->setSaison($saison);
            if ($equipeNiveau) {
                $equipe->setNiveau($equipeNiveau);
            }
            if (!$dryRun) {
                $this->em->persist($equipe);
                $this->em->flush();
                $io->success("Équipe créée (id=" . $equipe->getId() . ")");
            } else {
                $io->info("[DRY-RUN] Équipe serait créée.");
            }
        } else {
            $io->note("Équipe \"$equipeNom\" déjà existante (id=" . $equipe->getId() . ") → réutilisée.");
        }

        // === 3. Lecture du xlsx ===
        $io->section("Lecture du xlsx");
        try {
            $spreadsheet = IOFactory::load($xlsxPath);
            $sheet = $spreadsheet->getActiveSheet();
        } catch (\Throwable $e) {
            $io->error("Erreur lecture xlsx : " . $e->getMessage());
            return Command::FAILURE;
        }

        $rencontreRepo = $this->em->getRepository(Rencontre::class);
        $stats = [
            'lignes_lues'  => 0,
            'exempt'       => 0,
            'pas_mabb'     => 0,
            'deja_en_base' => 0,
            'creees'       => 0,
            'erreurs'      => 0,
        ];

        $headerRow = true;
        foreach ($sheet->toArray() as $row) {
            if ($headerRow) {
                $headerRow = false;
                continue;
            }

            $stats['lignes_lues']++;

            [
                $division, $numeroMatch, $equipe1, $equipe2, $dateStr, $heureStr,
                $salle, $codeEmarque, $score1, $forfait1, $score2, $forfait2
            ] = array_pad($row, 12, null);

            // Garde-fou : ligne complètement vide
            if (empty($numeroMatch) || empty($equipe1) || empty($equipe2)) {
                continue;
            }

            // Exempt : FFBB met "Exempt" pour les journées sans adversaire
            if (stripos((string) $equipe1, 'exempt') !== false || stripos((string) $equipe2, 'exempt') !== false) {
                $stats['exempt']++;
                continue;
            }

            // === Détection LOCAUX/VISITEURS MABB ===
            $equipe1Str = trim((string) $equipe1);
            $equipe2Str = trim((string) $equipe2);
            $mabbEstLocaux = mb_stripos($equipe1Str, $mabbPattern) !== false;
            $mabbEstVisiteurs = mb_stripos($equipe2Str, $mabbPattern) !== false;

            if (!$mabbEstLocaux && !$mabbEstVisiteurs) {
                $stats['pas_mabb']++;
                $io->warning("Ligne {$stats['lignes_lues']} : ni équipe 1 ni équipe 2 ne match MABB ($mabbPattern). Skip.");
                continue;
            }

            $domicile = $mabbEstLocaux;
            $adversaire = $mabbEstLocaux ? $this->cleanNomEquipe($equipe2Str) : $this->cleanNomEquipe($equipe1Str);

            // === Idempotence : check si Rencontre existe déjà ===
            $exists = $rencontreRepo->findOneBy([
                'club'         => $club,
                'equipe'       => $equipe,
                'numeroMatch'  => (string) $numeroMatch,
                'saison'       => $saison,
            ]);
            if ($exists !== null) {
                $stats['deja_en_base']++;
                continue;
            }

            // === Parse date (format français dd/mm/yyyy) ===
            try {
                $date = $this->parseDateFfbb((string) $dateStr, (string) $heureStr);
            } catch (\Throwable $e) {
                $stats['erreurs']++;
                $io->warning("Ligne {$stats['lignes_lues']} : date invalide \"$dateStr $heureStr\". Skip.");
                continue;
            }

            // === Création de la Rencontre ===
            $rencontre = new Rencontre();
            $rencontre->setClub($club);
            $rencontre->setEquipe($equipe);
            $rencontre->setDate($date);
            $rencontre->setAdversaire($adversaire);
            $rencontre->setDomicile($domicile);
            $rencontre->setLieu($salle !== null && $salle !== '' ? trim((string) $salle) : null);
            $rencontre->setNumeroMatch((string) $numeroMatch);
            $rencontre->setSaison($saison);
            $rencontre->setDivision($division !== null && $division !== '' ? trim((string) $division) : null);
            $rencontre->setCodeEmarque($codeEmarque !== null && $codeEmarque !== '' ? trim((string) $codeEmarque) : null);

            // Score : FFBB met les scores tels que dans le xlsx ("equipe 1" / "equipe 2")
            // Côté MABB : si LOCAUX → score_equipe = score1, sinon score_equipe = score2
            $scoreMabb = $mabbEstLocaux ? $score1 : $score2;
            $scoreAdv  = $mabbEstLocaux ? $score2 : $score1;
            if (is_numeric($scoreMabb)) {
                $rencontre->setScoreEquipe((int) $scoreMabb);
            }
            if (is_numeric($scoreAdv)) {
                $rencontre->setScoreAdverse((int) $scoreAdv);
            }

            // Forfait : "Oui"/"Non" en français
            $forfaitMabbStr = (string) ($mabbEstLocaux ? $forfait1 : $forfait2);
            $forfaitAdvStr  = (string) ($mabbEstLocaux ? $forfait2 : $forfait1);
            if (method_exists($rencontre, 'setForfaitEquipe')) {
                $rencontre->setForfaitEquipe(strcasecmp(trim($forfaitMabbStr), 'oui') === 0);
            }
            if (method_exists($rencontre, 'setForfaitAdverse')) {
                $rencontre->setForfaitAdverse(strcasecmp(trim($forfaitAdvStr), 'oui') === 0);
            }

            // Statut : si date passée → "joué", sinon "à venir"
            $statut = $date < new \DateTimeImmutable() ? 'joue' : 'a_venir';
            if (method_exists($rencontre, 'setStatut')) {
                $rencontre->setStatut($statut);
            }

            if (!$dryRun) {
                $this->em->persist($rencontre);
                $stats['creees']++;
            } else {
                $stats['creees']++;
                $io->writeln(sprintf(
                    "  [DRY-RUN] %s vs %s — %s — n°%s (%s) — score %s-%s",
                    $domicile ? 'LOCAUX' : 'EXT.',
                    $adversaire,
                    $date->format('d/m/Y H:i'),
                    $numeroMatch,
                    $division,
                    $scoreMabb ?? '?',
                    $scoreAdv ?? '?'
                ));
            }
        }

        if (!$dryRun && $stats['creees'] > 0) {
            $this->em->flush();
        }

        $io->section("Bilan");
        $io->table(['Métrique', 'Valeur'], [
            ['Lignes lues (hors entête)', $stats['lignes_lues']],
            ['Exempt (skip)', $stats['exempt']],
            ['Pas MABB (skip)', $stats['pas_mabb']],
            ['Déjà en base (skip)', $stats['deja_en_base']],
            ['Rencontres créées', $stats['creees']],
            ['Erreurs', $stats['erreurs']],
        ]);

        if ($dryRun) {
            $io->info("DRY-RUN : aucune écriture en base. Relancer sans --dry-run pour appliquer.");
        } else {
            $io->success("Import terminé : {$stats['creees']} rencontres créées.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse une date FFBB (format français) en DateTimeImmutable.
     * Formats acceptés : "dd/mm/yyyy" + "HH:MM" (ou vide).
     */
    private function parseDateFfbb(string $dateStr, string $heureStr): \DateTimeImmutable
    {
        $dateStr = trim($dateStr);
        $heureStr = trim($heureStr ?: '00:00');

        if ($dateStr === '') {
            throw new \InvalidArgumentException('Date vide.');
        }

        // Match dd/mm/yyyy
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dateStr, $m)) {
            $iso = sprintf('%04d-%02d-%02d %s', (int) $m[3], (int) $m[2], (int) $m[1], $heureStr);
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $iso);
            if ($date === false) {
                throw new \InvalidArgumentException("Date ISO invalide : $iso");
            }
            return $date;
        }

        // Fallback DateTime natif
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateStr . ' ' . $heureStr);
        if ($date === false) {
            throw new \InvalidArgumentException("Format date non reconnu : \"$dateStr $heureStr\"");
        }
        return $date;
    }

    /**
     * Nettoie le nom d'équipe FFBB : retire " (X) " (numéro de poule en fin de chaîne)
     * et trim. Ex: "PALS ATHLETIC CLUB GUISE (4) " → "PALS ATHLETIC CLUB GUISE"
     */
    private function cleanNomEquipe(string $nom): string
    {
        // Retire les éventuels " (X) " ou " (X)" en fin de chaîne
        $clean = preg_replace('#\s*\(\d+\)\s*$#', '', $nom);
        return trim((string) $clean);
    }
}
