<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use App\Repository\Core\ClubRepository;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\RencontreRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * B19 — Import des rencontres FFBB depuis un export Excel.
 *
 * Le fichier source est le `rechercherRencontre.xlsx` téléchargé depuis FBI
 * (système FFBB). Format attendu (12 colonnes) :
 *   Division | N° de match | Equipe 1 | Equipe 2 | Date | Heure | Salle |
 *   e-Marque V2 | Score 1 | Forfait 1 | Score 2 | Forfait 2
 *
 * Logique :
 *   - Identifie le club via son nom (ou via --club-slug)
 *   - Crée l'équipe correspondante si absente (option --equipe-nom)
 *   - Pour chaque ligne :
 *       - Strip "(N)" des noms d'équipes
 *       - Skip si adversaire = "Exempt"
 *       - Détermine domicile = MABB est dans "Equipe 1"
 *       - INSERT/UPDATE Rencontre par (club_id, numero_match, saison)
 *
 * Idempotent : peut être relancé sans créer de doublons (UNIQUE index B19 migration).
 *
 * Usage :
 *   php bin/console app:import-rencontres --file=path.xlsx --equipe-nom="Seniors F" --saison=2025-2026
 *   php bin/console app:import-rencontres --file=path.xlsx --dry-run
 */
#[AsCommand(
    name: 'app:import-rencontres',
    description: 'Import rencontres FFBB depuis rechercherRencontre.xlsx',
)]
class ImportRencontresFfbbCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepo,
        private readonly EquipeRepository $equipeRepo,
        private readonly RencontreRepository $rencontreRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file',       null, InputOption::VALUE_REQUIRED, 'Chemin vers rechercherRencontre.xlsx')
            ->addOption('club-slug',  null, InputOption::VALUE_OPTIONAL, 'Slug du club cible', 'mabb')
            ->addOption('club-nom',   null, InputOption::VALUE_OPTIONAL, 'Nom du club tel qu\'il apparaît dans la colonne Equipe', 'METROPOLE AMIENOISE BASKETBALL')
            ->addOption('equipe-nom', null, InputOption::VALUE_OPTIONAL, 'Nom de l\'équipe (créée si absente)', 'Seniors F')
            ->addOption('saison',     null, InputOption::VALUE_OPTIONAL, 'Saison (ex: 2025-2026)', '2025-2026')
            ->addOption('dry-run',    null, InputOption::VALUE_NONE,     'Affiche ce qui sera fait sans écrire en BDD');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getOption('file');
        if (!$file || !is_file($file)) {
            $io->error("Fichier introuvable : {$file}");
            return Command::FAILURE;
        }

        $clubSlug  = (string) $input->getOption('club-slug');
        $clubNomFfbb = strtoupper(trim((string) $input->getOption('club-nom')));
        $equipeNom = (string) $input->getOption('equipe-nom');
        $saison    = (string) $input->getOption('saison');
        $dryRun    = (bool) $input->getOption('dry-run');

        // === 1. Récup club ===
        $club = $this->clubRepo->findOneBy(['slug' => $clubSlug]);
        if ($club === null) {
            $io->error("Club avec slug '{$clubSlug}' introuvable. Crée-le d'abord ou précise --club-slug.");
            return Command::FAILURE;
        }
        $io->info("Club : {$club->getNom()} (id={$club->getId()})");

        // === 2. Récup / création équipe ===
        $equipe = $this->equipeRepo->findOneBy(['club' => $club, 'nom' => $equipeNom]);
        if ($equipe === null) {
            $io->warning("Équipe '{$equipeNom}' inexistante. Création...");
            if (!$dryRun) {
                $equipe = new Equipe();
                $equipe->setClub($club);
                $equipe->setNom($equipeNom);
                if (method_exists($equipe, 'setSaison')) {
                    $equipe->setSaison($saison);
                }
                if (method_exists($equipe, 'setCategorie')) {
                    $equipe->setCategorie('Sénior');
                }
                $this->em->persist($equipe);
                $this->em->flush();
            } else {
                $io->writeln("  [DRY-RUN] Équipe non créée");
            }
        } else {
            $io->info("Équipe trouvée : {$equipe->getNom()} (id={$equipe->getId()})");
        }

        // === 3. Parse Excel ===
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false); // pas de header → array indexé 0

        if (count($rows) < 2) {
            $io->error('Aucune ligne de données dans le fichier.');
            return Command::FAILURE;
        }

        // Vérifie le header
        $header = array_map(static fn($c) => strtolower(trim((string) $c)), $rows[0]);
        $expected = ['division', 'n° de match', 'equipe 1', 'equipe 2', 'date de rencontre'];
        foreach ($expected as $i => $exp) {
            if (!str_contains($header[$i] ?? '', explode(' ', $exp)[0])) {
                $io->error("Header inattendu colonne $i : '{$header[$i]}' (attendu : '$exp')");
                return Command::FAILURE;
            }
        }

        // === 4. Traitement des lignes ===
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'exempts' => 0];
        $rapport = [];

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            [$division, $numMatch, $eq1, $eq2, $date, $heure, $salle, $eMarque, $score1, $forfait1, $score2, $forfait2] =
                array_pad($row, 12, null);

            $eq1Clean = $this->stripFfbbSuffix((string) $eq1);
            $eq2Clean = $this->stripFfbbSuffix((string) $eq2);

            // Skip Exempt
            if ($eq1Clean === 'EXEMPT' || $eq2Clean === 'EXEMPT') {
                $stats['exempts']++;
                $rapport[] = ['SKIP', "Exempt match #$numMatch"];
                continue;
            }

            // Detect domicile : MABB est dans equipe 1 ?
            $mabbEnPosition1 = stripos($eq1Clean, $clubNomFfbb) !== false;
            $mabbEnPosition2 = stripos($eq2Clean, $clubNomFfbb) !== false;

            if (!$mabbEnPosition1 && !$mabbEnPosition2) {
                $io->warning("Ligne $i : ni eq1 ni eq2 ne contient '{$clubNomFfbb}'");
                $stats['skipped']++;
                continue;
            }

            $domicile = $mabbEnPosition1;
            $adversaire = $domicile ? $eq2Clean : $eq1Clean;
            $scoreEquipe = $domicile ? $this->intOrNull($score1) : $this->intOrNull($score2);
            $scoreAdverse = $domicile ? $this->intOrNull($score2) : $this->intOrNull($score1);
            $forfaitEq = $domicile ? $this->boolFr($forfait1) : $this->boolFr($forfait2);
            $forfaitAd = $domicile ? $this->boolFr($forfait2) : $this->boolFr($forfait1);

            // Parse date
            $dateObj = $this->parseDate((string) $date, (string) $heure);
            if ($dateObj === null) {
                $io->warning("Ligne $i : date invalide '$date $heure'");
                $stats['skipped']++;
                continue;
            }

            // === Idempotent : existing ? ===
            $existing = $this->rencontreRepo->findOneBy([
                'club'        => $club,
                'numeroMatch' => (string) $numMatch,
                'saison'      => $saison,
            ]);

            if ($existing !== null) {
                // UPDATE seulement les champs non destructifs (date, score, code)
                if (!$dryRun) {
                    $existing->setDate($dateObj);
                    $existing->setLieu((string) $salle);
                    $existing->setAdversaire($adversaire);
                    $existing->setDomicile($domicile);
                    $existing->setScoreEquipe($scoreEquipe);
                    $existing->setScoreAdverse($scoreAdverse);
                    $existing->setCodeEMarque((string) $eMarque ?: null);
                    $existing->setDivision((string) $division);
                    $existing->setForfaitEquipe($forfaitEq);
                    $existing->setForfaitAdverse($forfaitAd);
                }
                $stats['updated']++;
                $rapport[] = ['UPDATE', "Match #$numMatch vs $adversaire le " . $dateObj->format('d/m/Y')];
            } else {
                if (!$dryRun) {
                    $r = new Rencontre();
                    $r->setClub($club);
                    if ($equipe !== null) $r->setEquipe($equipe);
                    $r->setNumeroMatch((string) $numMatch);
                    $r->setSaison($saison);
                    $r->setDivision((string) $division);
                    $r->setDate($dateObj);
                    $r->setLieu((string) $salle);
                    $r->setAdversaire($adversaire);
                    $r->setDomicile($domicile);
                    $r->setScoreEquipe($scoreEquipe);
                    $r->setScoreAdverse($scoreAdverse);
                    $r->setCodeEMarque((string) $eMarque ?: null);
                    $r->setForfaitEquipe($forfaitEq);
                    $r->setForfaitAdverse($forfaitAd);
                    // Si scores présents → statut validé direct (match passé)
                    if ($scoreEquipe !== null && $scoreAdverse !== null) {
                        $r->setStatut(Rencontre::STATUT_VALIDE);
                    }
                    $this->em->persist($r);
                }
                $stats['created']++;
                $rapport[] = ['CREATE', "Match #$numMatch " . ($domicile ? 'DOM' : 'EXT') . " vs $adversaire — " . $dateObj->format('d/m/Y') . ($scoreEquipe !== null ? " ($scoreEquipe-$scoreAdverse)" : '')];
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // === 5. Rapport ===
        $io->table(['Action', 'Détail'], $rapport);
        $io->success(sprintf(
            "%s : %d créées, %d mises à jour, %d exempts, %d skip",
            $dryRun ? 'DRY-RUN' : 'IMPORT',
            $stats['created'],
            $stats['updated'],
            $stats['exempts'],
            $stats['skipped'],
        ));

        return Command::SUCCESS;
    }

    /** Strip " (9)" / " (1)" en fin de nom d'équipe FFBB. */
    private function stripFfbbSuffix(string $nom): string
    {
        return trim(preg_replace('/\s*\(\d+\)\s*$/', '', $nom));
    }

    /** "Oui" / "Non" → bool */
    private function boolFr(mixed $v): bool
    {
        $s = strtolower(trim((string) $v));
        return $s === 'oui' || $s === '1' || $s === 'true';
    }

    private function intOrNull(mixed $v): ?int
    {
        $s = trim((string) $v);
        return $s === '' ? null : (int) $s;
    }

    /** Parse "28/09/2025" + "15:30" → DateTimeImmutable Europe/Paris */
    private function parseDate(string $date, string $heure): ?\DateTimeImmutable
    {
        $date = trim($date);
        $heure = trim($heure) ?: '00:00';

        $formats = ['d/m/Y H:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d'];
        foreach ($formats as $fmt) {
            $combined = $date . ' ' . $heure;
            $dt = \DateTimeImmutable::createFromFormat($fmt, $combined, new \DateTimeZone('Europe/Paris'));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }
        return null;
    }
}
