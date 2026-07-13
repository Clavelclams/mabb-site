<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\JoueurEquipe;
use App\Service\Sport\CategorieCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le passage d'une saison à la suivante.
 *
 * Ce que fait la commande, et pourquoi elle le fait comme ça.
 *
 * ELLE DUPLIQUE LES ÉQUIPES, EN NETTOYANT LEUR NOM
 * ------------------------------------------------
 * Beaucoup d'équipes s'appellent « U15 Régional HDF 2025-2026 ». Recopier ce nom tel
 * quel donnerait une équipe 2026-2027 nommée 2025-2026. La saison est une colonne, elle
 * n'a rien à faire dans le nom : on l'en retire.
 *
 * ELLE GARDE CHAQUE JOUEUSE DANS SON ÉQUIPE
 * -----------------------------------------
 * C'est le point important, et la version précédente s'en moquait. Elle recalculait la
 * catégorie de chacune depuis sa date de naissance, puis prenait la première équipe de
 * cette catégorie par ordre alphabétique. Sur un club qui a une A et une B dans chaque
 * catégorie, TOUTES les joueuses atterrissaient dans la première, et la B disparaissait.
 * Quatre-vingt-neuf affectations à refaire à la main.
 *
 * La bonne règle est plus simple : une joueuse qui ne change pas de catégorie ne change
 * pas d'équipe. Sa A reste sa A, sa B reste sa B. On ne réarbitre que celles qui ont
 * pris un an et montent de catégorie, et là encore on essaie de conserver leur niveau
 * (une joueuse de la B départementale a plus de chances d'aller en B départementale).
 *
 * ELLE CONSERVE LES SURCLASSEMENTS
 * --------------------------------
 * Une U15 qui jouait aussi en U18 doit continuer à jouer dans les deux. Les affectations
 * secondaires (surclassement, doublage, réserve) sont reportées sur les équipes
 * correspondantes de la nouvelle saison.
 *
 * ELLE N'ÉCRIT RIEN SANS --apply. Le dry-run est le mode par défaut, et il doit le rester.
 *
 * Usage :
 *   php bin/console app:passage-saison --to=2026-2027
 *   php bin/console app:passage-saison --to=2026-2027 --apply
 *   php bin/console app:passage-saison --to=2026-2027 --club-id=2 --apply
 */
#[AsCommand(
    name: 'app:passage-saison',
    description: 'Reconduit les equipes et les joueuses vers la nouvelle saison, en gardant chacune dans son equipe',
)]
class PassageSaisonCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategorieCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Saison cible, ex: 2026-2027')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Saison source (défaut : saison cible − 1)')
            ->addOption('club-id', null, InputOption::VALUE_OPTIONAL, 'Limiter à un club')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit en base (sinon dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $to = (string) $input->getOption('to');
        if (!preg_match('/^\d{4}-\d{4}$/', $to)) {
            $io->error('--to est requis au format YYYY-YYYY (ex: 2026-2027).');

            return Command::FAILURE;
        }

        $from   = (string) ($input->getOption('from') ?: (((int) explode('-', $to)[0] - 1) . '-' . ((int) explode('-', $to)[1] - 1)));
        $apply  = (bool) $input->getOption('apply');
        $clubId = $input->getOption('club-id') !== null ? (int) $input->getOption('club-id') : null;

        $io->title(sprintf(
            'Passage de saison %s vers %s %s',
            $from,
            $to,
            $apply ? '(ÉCRITURE EN BASE)' : '(simulation, rien ne sera écrit)'
        ));

        // ─────────────────────────────────────────────────────────────────────
        // 1. Les équipes : on duplique, en nettoyant le nom.
        // ─────────────────────────────────────────────────────────────────────
        $equipesSources = $this->em->getRepository(Equipe::class)->findBy(
            ['saison' => $from, 'isActive' => true],
            ['nom' => 'ASC']
        );
        if ($clubId !== null) {
            $equipesSources = array_values(array_filter(
                $equipesSources,
                fn (Equipe $e) => $e->getClub()?->getId() === $clubId
            ));
        }

        if ($equipesSources === []) {
            $io->warning("Aucune équipe active en {$from}. Rien à reconduire.");

            return Command::SUCCESS;
        }

        // Ce qui existe déjà en saison cible, pour pouvoir relancer la commande sans
        // créer de doublons.
        $cibleParCle = [];
        foreach ($this->em->getRepository(Equipe::class)->findBy(['saison' => $to]) as $e) {
            $cibleParCle[$this->cleEquipe($e->getClub()?->getId(), $e->getNom())] = $e;
        }

        /** @var array<int, Equipe> $mapSourceVersCible  [id équipe source => équipe cible] */
        $mapSourceVersCible = [];
        $nbCreees = 0;

        foreach ($equipesSources as $src) {
            $nomPropre = $this->nettoyerNom((string) $src->getNom());
            $cle       = $this->cleEquipe($src->getClub()?->getId(), $nomPropre);

            if (isset($cibleParCle[$cle])) {
                $mapSourceVersCible[$src->getId()] = $cibleParCle[$cle];
                $io->text("  = existe déjà : {$nomPropre}");
                continue;
            }

            $nouvelle = new Equipe();
            $nouvelle->setClub($src->getClub());
            $nouvelle->setNom($nomPropre);
            $nouvelle->setCategorie($src->getCategorie());
            $nouvelle->setNiveau($src->getNiveau());
            $nouvelle->setSaison($to);
            $nouvelle->setIsActive(true);

            if ($apply) {
                $this->em->persist($nouvelle);
            }

            $cibleParCle[$cle]                 = $nouvelle;
            $mapSourceVersCible[$src->getId()] = $nouvelle;
            $nbCreees++;

            $renomme = $nomPropre !== $src->getNom() ? "   (renommée depuis « {$src->getNom()} »)" : '';
            $io->text("  + {$nomPropre} · {$src->getCategorie()}{$renomme}");
        }

        if ($apply) {
            $this->em->flush();
        }

        // Index des équipes cibles par club et par catégorie, pour les montées.
        $parClubCategorie = [];
        foreach ($cibleParCle as $e) {
            $parClubCategorie[$e->getClub()?->getId()][strtoupper((string) $e->getCategorie())][] = $e;
        }

        // ─────────────────────────────────────────────────────────────────────
        // 2. Les joueuses.
        // ─────────────────────────────────────────────────────────────────────
        $joueuses = $this->em->getRepository(Joueur::class)->findBy(
            ['isActive' => true, 'isTemporaire' => false],
            ['nom' => 'ASC']
        );
        if ($clubId !== null) {
            $joueuses = array_values(array_filter(
                $joueuses,
                fn (Joueur $j) => $j->getClub()?->getId() === $clubId
            ));
        }

        $jeRepo = $this->em->getRepository(JoueurEquipe::class);

        $reconduites   = [];  // même catégorie : elle reste où elle est
        $montees       = [];  // elle change de catégorie, niveau conservé
        $aVerifier     = [];  // elle change de catégorie, plusieurs choix : à trancher
        $aArbitrer     = [];  // impossible de décider
        $surclassements = 0;

        foreach ($joueuses as $j) {
            $cidJ      = $j->getClub()?->getId();
            $categorie = $this->calculator->categorie($j, $to);

            if ($categorie === null) {
                $aArbitrer[] = [$j->getNomComplet(), 'Pas de date de naissance, catégorie incalculable'];
                continue;
            }

            $ancienne = $this->equipePrecedente($j, $from, $jeRepo);

            // Le cas le plus fréquent, et celui que l'ancienne version ratait :
            // elle reste dans la même catégorie, donc elle reste dans son équipe.
            if ($ancienne !== null
                && strtoupper((string) $ancienne->getCategorie()) === strtoupper($categorie)
                && isset($mapSourceVersCible[$ancienne->getId()])
            ) {
                $cible = $mapSourceVersCible[$ancienne->getId()];
                $this->affecter($j, $cible, $to, JoueurEquipe::TYPE_PRINCIPALE, $apply, $jeRepo);
                $reconduites[] = [$j->getNomComplet(), $categorie, $cible->getNom()];
                $surclassements += $this->reporterSurclassements($j, $from, $to, $mapSourceVersCible, $apply, $jeRepo);
                continue;
            }

            // Elle a changé de catégorie (ou n'avait pas d'équipe). Il faut lui en
            // trouver une, et si possible au même niveau qu'avant.
            $candidates = $parClubCategorie[$cidJ][strtoupper($categorie)] ?? [];

            if ($candidates === []) {
                // Pas d'équipe dans sa catégorie : on tente la catégorie supérieure
                // compatible, comme avant.
                $cible = $this->trouverEquipeCompatible($parClubCategorie[$cidJ] ?? [], $j, $to);
                if ($cible === null) {
                    $aArbitrer[] = [
                        $j->getNomComplet(),
                        sprintf('Catégorie %s, aucune équipe compatible en %s', $categorie, $to),
                    ];
                    continue;
                }
                $this->affecter($j, $cible, $to, JoueurEquipe::TYPE_PRINCIPALE, $apply, $jeRepo);
                $montees[] = [$j->getNomComplet(), $categorie, $cible->getNom(), 'surclassement automatique'];
                continue;
            }

            if (\count($candidates) === 1) {
                $cible = $candidates[0];
                $this->affecter($j, $cible, $to, JoueurEquipe::TYPE_PRINCIPALE, $apply, $jeRepo);
                $montees[] = [$j->getNomComplet(), $categorie, $cible->getNom(), 'seule équipe possible'];
                $surclassements += $this->reporterSurclassements($j, $from, $to, $mapSourceVersCible, $apply, $jeRepo);
                continue;
            }

            // Plusieurs équipes dans la nouvelle catégorie. On essaie de conserver son
            // niveau : une fille qui était en départementale a plus de chances de rester
            // en départementale qu'en régionale. Ce n'est qu'une présomption, on la signale.
            $memeNiveau = array_values(array_filter(
                $candidates,
                fn (Equipe $e) => $ancienne !== null
                    && $e->getNiveau() !== null
                    && mb_strtolower((string) $e->getNiveau()) === mb_strtolower((string) $ancienne->getNiveau())
            ));

            if (\count($memeNiveau) === 1) {
                $cible = $memeNiveau[0];
                $this->affecter($j, $cible, $to, JoueurEquipe::TYPE_PRINCIPALE, $apply, $jeRepo);
                $montees[] = [
                    $j->getNomComplet(),
                    $categorie,
                    $cible->getNom(),
                    'même niveau qu\'en ' . $from,
                ];
                $surclassements += $this->reporterSurclassements($j, $from, $to, $mapSourceVersCible, $apply, $jeRepo);
                continue;
            }

            // On ne sait pas trancher. On l'affecte à la première pour qu'elle ne reste
            // pas orpheline, mais on le dit franchement : c'est à vérifier.
            usort($candidates, fn (Equipe $a, Equipe $b) => strcmp((string) $a->getNom(), (string) $b->getNom()));
            $cible = $candidates[0];
            $this->affecter($j, $cible, $to, JoueurEquipe::TYPE_PRINCIPALE, $apply, $jeRepo);
            $aVerifier[] = [
                $j->getNomComplet(),
                $categorie,
                $ancienne?->getNom() ?? 'aucune',
                $cible->getNom(),
                \count($candidates) . ' équipes possibles',
            ];
        }

        if ($apply) {
            $this->em->flush();
        }

        // ─────────────────────────────────────────────────────────────────────
        // 3. Le rapport. Il doit se lire vite, et dire où porter l'attention.
        // ─────────────────────────────────────────────────────────────────────
        $io->newLine();
        $io->success(sprintf(
            '%d équipe(s) créée(s). %d joueuse(s) reconduites dans leur équipe, %d montée(s) de catégorie, %d à vérifier, %d à traiter à la main. %d surclassement(s) reporté(s).%s',
            $nbCreees,
            \count($reconduites),
            \count($montees),
            \count($aVerifier),
            \count($aArbitrer),
            $surclassements,
            $apply ? '' : "\nSimulation : relancer avec --apply pour écrire."
        ));

        if ($montees !== []) {
            $io->section('Montées de catégorie (à survoler)');
            $io->table(['Joueuse', 'Nouvelle catégorie', 'Équipe', 'Pourquoi celle-là'], $montees);
        }

        if ($aVerifier !== []) {
            $io->section('À VÉRIFIER : plusieurs équipes possibles, le choix est arbitraire');
            $io->table(['Joueuse', 'Catégorie', 'Équipe en ' . $from, 'Équipe choisie', 'Détail'], $aVerifier);
        }

        if ($aArbitrer !== []) {
            $io->section('À TRAITER À LA MAIN : la commande ne peut pas décider');
            $io->table(['Joueuse', 'Raison'], $aArbitrer);
        }

        if ($reconduites !== [] && $io->isVerbose()) {
            $io->section('Reconduites à l\'identique');
            $io->table(['Joueuse', 'Catégorie', 'Équipe'], $reconduites);
        } elseif ($reconduites !== []) {
            $io->comment(sprintf(
                '%d joueuses gardent leur équipe. Relancer avec -v pour les lister.',
                \count($reconduites)
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Où jouait-elle la saison dernière ?
     *
     * On regarde d'abord son affectation principale de la saison source. Si elle n'en a
     * pas (fiche ancienne, import partiel), on retombe sur Joueur.equipe, le champ
     * historique, à condition qu'il pointe bien vers une équipe de cette saison.
     */
    private function equipePrecedente(Joueur $j, string $from, $jeRepo): ?Equipe
    {
        $je = $jeRepo->findOneBy([
            'joueur' => $j,
            'saison' => $from,
            'type'   => JoueurEquipe::TYPE_PRINCIPALE,
            'actif'  => true,
        ]);

        if ($je?->getEquipe() !== null) {
            return $je->getEquipe();
        }

        $equipe = $j->getEquipe();

        return ($equipe !== null && $equipe->getSaison() === $from) ? $equipe : null;
    }

    /**
     * Reporte les affectations secondaires : surclassement, doublage, réserve.
     *
     * Une U15 qui jouait aussi en U18 doit continuer à jouer dans les deux. Sans ça, le
     * surclassement se perdrait à chaque changement de saison, et le staff devrait le
     * ressaisir tous les ans.
     */
    private function reporterSurclassements(
        Joueur $j,
        string $from,
        string $to,
        array $mapSourceVersCible,
        bool $apply,
        $jeRepo,
    ): int {
        $secondaires = $jeRepo->findBy([
            'joueur' => $j,
            'saison' => $from,
            'actif'  => true,
        ]);

        $n = 0;
        foreach ($secondaires as $je) {
            if ($je->getType() === JoueurEquipe::TYPE_PRINCIPALE) {
                continue;
            }

            $src = $je->getEquipe();
            if ($src === null || !isset($mapSourceVersCible[$src->getId()])) {
                continue;
            }

            $this->affecter($j, $mapSourceVersCible[$src->getId()], $to, $je->getType(), $apply, $jeRepo);
            $n++;
        }

        return $n;
    }

    /** Crée l'affectation si elle n'existe pas déjà. Ne fait rien en simulation. */
    private function affecter(Joueur $j, Equipe $equipe, string $saison, string $type, bool $apply, $jeRepo): void
    {
        if (!$apply) {
            return;
        }

        $deja = $jeRepo->findOneBy(['joueur' => $j, 'equipe' => $equipe, 'saison' => $saison]);
        if ($deja !== null) {
            return;
        }

        $je = new JoueurEquipe();
        $je->setJoueur($j);
        $je->setEquipe($equipe);
        $je->setSaison($saison);
        $je->setType($type);
        $this->em->persist($je);

        // Joueur.equipe reste le champ de référence pour tout le code historique.
        // Seule l'affectation principale le met à jour.
        if ($type === JoueurEquipe::TYPE_PRINCIPALE) {
            $j->setEquipe($equipe);
        }
    }

    /**
     * Aucune équipe dans sa catégorie : on cherche la plus proche catégorie supérieure
     * où elle a le droit de jouer.
     *
     * @param array<string, Equipe[]> $parCategorie
     */
    private function trouverEquipeCompatible(array $parCategorie, Joueur $j, string $saison): ?Equipe
    {
        $candidates = [];
        foreach ($parCategorie as $cat => $equipes) {
            foreach ($equipes as $e) {
                if ($this->calculator->estCompatible($j, (string) $e->getCategorie(), $saison)) {
                    $poids = preg_match('/^U(\d{1,2})/i', $cat, $m) ? (int) $m[1] : 99;
                    $candidates[] = [$poids, $e];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $a[0] <=> $b[0] ?: strcmp((string) $a[1]->getNom(), (string) $b[1]->getNom()));

        return $candidates[0][1];
    }

    /**
     * Retire le millésime du nom d'une équipe.
     *
     * « U15 Régional HDF 2025-2026 » devient « U15 Régional HDF ». La saison est une
     * colonne à part : la laisser dans le nom produirait une équipe 2026-2027 appelée
     * 2025-2026, ce que personne ne comprendrait.
     */
    private function nettoyerNom(string $nom): string
    {
        // 2025-2026, 2025/2026, 2025-26, 25-26
        $nom = preg_replace('/\s*\b\d{2,4}\s*[-\/]\s*\d{2,4}\b\s*/u', ' ', $nom) ?? $nom;

        return trim(preg_replace('/\s{2,}/u', ' ', $nom) ?? $nom);
    }

    private function cleEquipe(?int $clubId, ?string $nom): string
    {
        return $clubId . '|' . mb_strtolower(trim((string) $nom));
    }
}
