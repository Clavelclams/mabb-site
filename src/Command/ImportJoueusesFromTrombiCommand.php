<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\JoueurEquipe;
use Doctrine\ORM\EntityManagerInterface;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import des joueuses depuis un PDF "trombinoscope FFBB".
 *
 * STRUCTURE PDF FFBB (constante d'une équipe à l'autre, pattern par joueuse) :
 *   Généralités Sportif
 *   Prénom - NOM Licence XC - UXX
 *   BCXXXXXX - JJ/MM/AAAA [Surclassements : NS, D13, D18, D20...]
 *   HDF0080036 - METROPOLE AMIENOISE BASKETBALL X - Formule X
 *   JJ/MM/AAAA - Féminin
 *
 * COMPORTEMENT :
 *   - Pour chaque joueuse trouvée dans le PDF :
 *     1. Cherche en base par licence FFBB (clé unique depuis V1.3)
 *     2. Si trouvée → réutilise le Joueur existant
 *     3. Si nouvelle → crée Joueur (avec date naissance + licence)
 *     4. Crée/update JoueurEquipe pour l'équipe cible (type configuré)
 *
 * IDEMPOTENCE :
 *   - Une joueuse déjà liée à cette équipe avec ce type n'est pas re-créée
 *   - Si liée avec un autre type → option --update-type pour changer
 *
 * USAGE :
 *   php bin/console app:import-joueuses-from-trombi \
 *       "ressource/trombi_u18.pdf" \
 *       --equipe-id=7 \
 *       --type=principale \
 *       --club-id=2
 */
#[AsCommand(
    name: 'app:import-joueuses-from-trombi',
    description: 'Import des joueuses d\'une équipe depuis un PDF trombinoscope FFBB.'
)]
final class ImportJoueusesFromTrombiCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Chemin du PDF trombinoscope.')
            ->addOption('equipe-id', null, InputOption::VALUE_REQUIRED, 'ID de l\'équipe MABB cible.')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL,
                'Type d\'affectation : principale, doublage, surclassement, reserve.', 'principale')
            ->addOption('saison', null, InputOption::VALUE_OPTIONAL, 'Saison ISO.', '2025-2026')
            ->addOption('club-id', null, InputOption::VALUE_OPTIONAL, 'ID du Club MABB.', '2')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire.')
            ->addOption('set-equipe-principale', null, InputOption::VALUE_NONE,
                'Si --type=principale, force aussi Joueur.equipe pour les nouvelles joueuses (rétrocompat).')
            ->addOption('debug', null, InputOption::VALUE_NONE,
                'Affiche le texte brut extrait par smalot/pdfparser pour debug du regex.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pdfPath = $input->getArgument('pdf');
        $equipeId = (int) $input->getOption('equipe-id');
        $type = (string) $input->getOption('type');
        $saison = (string) $input->getOption('saison');
        $clubId = (int) $input->getOption('club-id');
        $dryRun = (bool) $input->getOption('dry-run');
        $setEquipePrincipale = (bool) $input->getOption('set-equipe-principale');
        $debug = (bool) $input->getOption('debug');

        // === Garde-fous ===
        if (!is_file($pdfPath)) {
            $io->error("PDF introuvable : $pdfPath");
            return Command::FAILURE;
        }
        if (!in_array($type, JoueurEquipe::TYPES, true)) {
            $io->error("Type invalide : $type. Attendu : " . implode(', ', JoueurEquipe::TYPES));
            return Command::FAILURE;
        }

        $equipe = $this->em->getRepository(Equipe::class)->find($equipeId);
        if (!$equipe) {
            $io->error("Équipe id=$equipeId introuvable.");
            return Command::FAILURE;
        }

        $club = $this->em->getRepository(\App\Entity\Core\Club::class)->find($clubId);
        if (!$club) {
            $io->error("Club id=$clubId introuvable.");
            return Command::FAILURE;
        }

        if ($equipe->getClub()->getId() !== $club->getId()) {
            $io->error("Équipe et club ne correspondent pas.");
            return Command::FAILURE;
        }

        $io->title("Import joueuses trombinoscope FFBB");
        $io->table(['Paramètre', 'Valeur'], [
            ['PDF', basename($pdfPath)],
            ['Équipe', $equipe->getNom() . ' (id=' . $equipe->getId() . ')'],
            ['Type', JoueurEquipe::TYPE_LABELS[$type] ?? $type],
            ['Saison', $saison],
            ['Mode', $dryRun ? 'DRY-RUN' : 'WRITE'],
            ['Force Joueur.equipe', $setEquipePrincipale ? 'OUI' : 'NON'],
        ]);

        // === Parsing PDF ===
        $io->section("Parsing PDF…");
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            $io->error("Erreur parsing PDF : " . $e->getMessage());
            return Command::FAILURE;
        }

        // === Mode debug : dump du texte brut pour analyser le format ===
        if ($debug) {
            $io->section("DEBUG — Texte brut extrait par smalot/pdfparser");
            $io->writeln("Longueur totale : " . strlen($text) . " caractères");
            $lines = preg_split('/\r?\n/', $text);
            $io->writeln("Nombre de lignes : " . count($lines));
            $io->writeln("--- 50 premières lignes (avec numéros) ---");
            foreach (array_slice($lines, 0, 50) as $idx => $line) {
                $io->writeln(sprintf("[%03d] %s", $idx, $line));
            }
            $io->writeln("--- fin debug ---");
        }

        $joueuses = $this->extractJoueuses($text);
        $io->info(count($joueuses) . " joueuses détectées dans le PDF.");

        if (empty($joueuses)) {
            $io->warning("Aucune joueuse extraite. Vérifier que le PDF est bien un trombinoscope FFBB.");
            return Command::SUCCESS;
        }

        // === Import ===
        $stats = [
            'detectees'             => count($joueuses),
            'joueur_existant'       => 0,
            'joueur_cree'           => 0,
            'affectation_existante' => 0,
            'affectation_creee'     => 0,
            'auto_degradee_doublage' => 0,
            'erreurs'               => 0,
        ];

        // Liste pour rapport final des joueuses auto-dégradées
        $autoDegradees = [];

        $joueurRepo = $this->em->getRepository(Joueur::class);

        foreach ($joueuses as $j) {
            try {
                $joueur = null;

                // 1. Cherche par licence FFBB (clé unique depuis V1.3)
                if ($j['licence']) {
                    $joueur = $joueurRepo->findOneBy(['licence' => $j['licence']]);
                }

                // 2. Fallback : cherche par (prénom, nom) dans le club
                if ($joueur === null) {
                    $joueur = $joueurRepo->findOneBy([
                        'club'   => $club,
                        'prenom' => $j['prenom'],
                        'nom'    => $j['nom'],
                    ]);
                }

                // 2bis. Si trouvée par nom mais SANS licence, on enrichit avec celle du trombi
                // (cas typique : joueuse créée manuellement avant l'import FFBB)
                if ($joueur !== null && $joueur->getLicence() === null && $j['licence']) {
                    $joueur->setLicence($j['licence']);
                    $io->writeln("  📝 Licence ajoutée à {$j['prenom']} {$j['nom']} : {$j['licence']}");
                }

                // 2ter. Si trouvée mais SANS date de naissance, on enrichit
                if ($joueur !== null && $joueur->getDateNaissance() === null && $j['date_naissance']) {
                    try {
                        $date = \DateTimeImmutable::createFromFormat('d/m/Y', $j['date_naissance']);
                        if ($date !== false) {
                            $joueur->setDateNaissance($date);
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }

                // 3. Création si pas trouvée
                if ($joueur === null) {
                    $joueur = new Joueur();
                    $joueur->setClub($club);
                    $joueur->setPrenom($j['prenom']);
                    $joueur->setNom($j['nom']);
                    if ($j['licence']) {
                        $joueur->setLicence($j['licence']);
                    }
                    if ($j['date_naissance']) {
                        try {
                            $date = \DateTimeImmutable::createFromFormat('d/m/Y', $j['date_naissance']);
                            if ($date !== false) {
                                $joueur->setDateNaissance($date);
                            }
                        } catch (\Throwable $e) {
                            // skip date parse errors silencieusement
                        }
                    }
                    if ($setEquipePrincipale && $type === JoueurEquipe::TYPE_PRINCIPALE) {
                        $joueur->setEquipe($equipe);
                    }
                    $joueur->setIsActive(true);

                    if (!$dryRun) {
                        $this->em->persist($joueur);
                        $this->em->flush(); // flush pour avoir l'ID dispo
                    }
                    $stats['joueur_cree']++;
                    $io->writeln("  ➕ Création joueuse : {$j['prenom']} {$j['nom']} ({$j['licence']})");
                } else {
                    $stats['joueur_existant']++;
                }

                // 4. Affectation à l'équipe : check si existe déjà
                $affectationExistante = null;
                foreach ($joueur->getAffectations() as $aff) {
                    if ($aff->getEquipe()?->getId() === $equipe->getId() && $aff->getSaison() === $saison) {
                        $affectationExistante = $aff;
                        break;
                    }
                }

                if ($affectationExistante) {
                    $stats['affectation_existante']++;
                    continue;
                }

                // GARDE-FOU INVARIANT : si on essaie de créer une 2e affectation
                // type=principale alors qu'il en existe déjà une, on la dégrade
                // automatiquement en doublage et on le signale.
                // Permet de lancer la commande sur plusieurs trombi avec --type=principale
                // sans casser l'invariant "une seule principale par joueuse".
                $typeEffectif = $type;
                if ($type === JoueurEquipe::TYPE_PRINCIPALE) {
                    $aDejaPrincipale = false;
                    foreach ($joueur->getAffectations() as $aff) {
                        if ($aff->isPrincipale() && $aff->getSaison() === $saison) {
                            $aDejaPrincipale = true;
                            break;
                        }
                    }
                    if ($aDejaPrincipale) {
                        $typeEffectif = JoueurEquipe::TYPE_DOUBLAGE;
                        $stats['auto_degradee_doublage']++;
                        $autoDegradees[] = sprintf(
                            '%s %s (licence %s)',
                            $j['prenom'], $j['nom'], $j['licence'] ?? '?'
                        );
                    }
                }

                $affectation = new JoueurEquipe();
                $affectation->setJoueur($joueur);
                $affectation->setEquipe($equipe);
                $affectation->setType($typeEffectif);
                $affectation->setSaison($saison);
                $affectation->setActif(true);

                // Note : surclassements détectés dans le PDF (D13, D18, D20...)
                if (!empty($j['surclassements'])) {
                    $affectation->setNotes('Autorisations FFBB : ' . implode(', ', $j['surclassements']));
                }

                if (!$dryRun) {
                    $this->em->persist($affectation);
                }
                $stats['affectation_creee']++;

            } catch (\Throwable $e) {
                $stats['erreurs']++;
                $io->warning("Erreur sur {$j['prenom']} {$j['nom']} : " . $e->getMessage());
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        // === Bilan ===
        $io->section("Bilan");
        $io->table(['Métrique', 'Valeur'], [
            ['Joueuses détectées', $stats['detectees']],
            ['Joueurs déjà en base', $stats['joueur_existant']],
            ['Joueurs créés', $stats['joueur_cree']],
            ['Affectations déjà existantes', $stats['affectation_existante']],
            ['Affectations créées', $stats['affectation_creee']],
            ['Auto-dégradées en doublage', $stats['auto_degradee_doublage']],
            ['Erreurs', $stats['erreurs']],
        ]);

        if (!empty($autoDegradees)) {
            $io->section("⚠️  Joueuses auto-dégradées en doublage (déjà principales ailleurs)");
            $io->info("Tu peux changer leur type via le Manager → fiche joueuse → section Affectations.");
            foreach ($autoDegradees as $entry) {
                $io->writeln("  → $entry");
            }
        }

        if ($dryRun) {
            $io->info("DRY-RUN : aucune écriture en base.");
        } else {
            $io->success("Import terminé.");
        }

        return Command::SUCCESS;
    }

    /**
     * Parse le texte d'un PDF trombinoscope FFBB extrait par smalot/pdfparser.
     *
     * STRUCTURE smalot (différente de pdfplumber Python) — pattern par joueuse :
     *   "Sportif"                                       (marqueur)
     *   "Licence XC - UXXPrénom - NOM"                  (joueuse — format inversé et collé)
     *   "BCXXXXXX - JJ/MM/AAAA"                         (licence FFBB)
     *   "JJ/MM/AAAA - Féminin" ou "Masculin"            (date naissance + sexe)
     *   "HDF0080036 - METROPOLE AMIENOISE BASKETBALL"  (club)
     *   ["NS"|"D13"|"D18 D20"...]                       (surclassements éventuels, ligne suivante)
     *   "N - Pas d'assurance" ou "A - Formule A"        (assurance)
     *
     * FILTRES MÉTIER :
     *   - On exclut les profils "Masculin" (coachs/dirigeants licenciés mais pas joueuses)
     *   - On accepte les catégories U7 à U20 + "Seniors" (un trombi peut contenir des seniors qui surclassent)
     *
     * @return array<int, array{prenom: string, nom: string, licence: ?string, date_naissance: ?string, surclassements: array<int, string>, categorie_age: ?string}>
     */
    private function extractJoueuses(string $text): array
    {
        $joueuses = [];
        $lines = preg_split('/\r?\n/', $text);
        $nbLines = count($lines);

        for ($i = 0; $i < $nbLines; $i++) {
            $line = trim($lines[$i]);

            // Le pattern à matcher est "Licence XC - UXXPrénom - NOM"
            // L'enchaînement "U18Elissa" sans espace est l'astuce typique de smalot.
            // On capture la catégorie d'âge (UXX, Seniors) ET le nom collé.
            // Regex : "Licence" + code (0C, 1C, 2C...) + " - " + catégorie + Prénom + " - " + NOM
            // - catégorie : "U" + chiffres OU "Seniors" OU "Senior X"
            if (!preg_match(
                '/^Licence\s+\w+\s*-\s*(U\d+|Seniors?|Loisir(?:\s+Mixte)?)([\p{L}\s\'-]+?)\s*-\s*([\p{Lu}][\p{Lu}\s\'-]*?)$/u',
                $line,
                $mName
            )) {
                continue;
            }

            $categorieAge = trim($mName[1]);
            $prenom       = trim($mName[2]);
            $nom          = trim($mName[3]);

            // Lignes suivantes
            $licenceLine = trim($lines[$i+1] ?? '');
            $birthLine   = trim($lines[$i+2] ?? '');

            // FILTRE : on ne garde QUE les profils "Féminin" — exclut les coachs/dirigeants masculins
            if (stripos($birthLine, 'Masculin') !== false) {
                continue;
            }

            // Parse licence "BCXXXXXX - JJ/MM/AAAA"
            $licence = null;
            if (preg_match('/^(BC\d{6,8})\s*-\s*\d{2}\/\d{2}\/\d{4}/u', $licenceLine, $mLic)) {
                $licence = $mLic[1];
            }

            // Parse date naissance "JJ/MM/AAAA - Féminin"
            $dateNaissance = null;
            if (preg_match('/^(\d{2}\/\d{2}\/\d{4})\s*-\s*F[eé]minin/iu', $birthLine, $mBirth)) {
                $dateNaissance = $mBirth[1];
            }

            // Cherche les surclassements dans les 3 lignes suivantes
            // (format "NS" ou "D13" ou "D18 D20", parfois "N20 DS" pour les coachs/seniors)
            $surclassements = [];
            for ($j = $i+3; $j <= $i+6 && $j < $nbLines; $j++) {
                $next = trim($lines[$j] ?? '');
                if ($next === '' || $next === 'NS') break;
                // Match "D13", "D18 D20", etc.
                if (preg_match_all('/\bD(\d+)\b/u', $next, $mSurcl)) {
                    foreach ($mSurcl[0] as $tok) {
                        $surclassements[] = $tok;
                    }
                    break;
                }
                // Si on tombe sur "N - Pas d'assurance" ou "A - Formule A" → fin de joueuse
                if (preg_match('/^[NA]\s*-\s*(Pas|Formule)/u', $next)) {
                    break;
                }
            }

            $joueuses[] = [
                'prenom'         => $prenom,
                'nom'            => $nom,
                'licence'        => $licence,
                'date_naissance' => $dateNaissance,
                'surclassements' => $surclassements,
                'categorie_age'  => $categorieAge,
            ];
        }

        return $joueuses;
    }
}
