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
 * [V2.4 05/07/2026] PASSAGE DE SAISON AUTOMATIQUE.
 *
 * Répond au besoin : "à la nouvelle saison, doit-on retaper les équipes et
 * les catégories des joueuses à la main ?" → NON, cette commande fait tout :
 *
 *   1. DUPLIQUE les équipes actives de la saison source vers la saison
 *      cible (même nom / catégorie / niveau / club). Idempotent : une
 *      équipe déjà existante (même nom + saison + club) n'est pas recréée.
 *
 *   2. RÉ-AFFECTE chaque joueuse active (non temporaire) automatiquement :
 *      catégorie CALCULÉE depuis sa DATE DE NAISSANCE (CategorieCalculator,
 *      règle FFBB : année de fin de saison − année de naissance), puis
 *      recherche d'une équipe de la nouvelle saison :
 *        a. même catégorie exacte (ex: U15 → équipe "U15")
 *        b. sinon la plus proche catégorie SUPÉRIEURE compatible
 *           (ex: âge 14 sans équipe U15 → équipe U16 si elle existe)
 *      → création de la JoueurEquipe (principale) + mise à jour de
 *        Joueur.equipe (équipe de référence).
 *
 *   3. LISTE les cas à arbitrer À LA MAIN (le coach garde la décision) :
 *      - joueuse sans date de naissance
 *      - aucune équipe compatible dans la nouvelle saison
 *      - plusieurs équipes de même catégorie (A/B) → affectée à la première
 *        par ordre alphabétique, signalée dans le rapport
 *
 * SÉCURITÉ : DRY-RUN PAR DÉFAUT. Rien n'est écrit sans --apply.
 * MULTI-TENANT : traite chaque club séparément (--club-id pour cibler).
 *
 * Usage :
 *   php bin/console app:passage-saison --to=2026-2027                 (dry-run)
 *   php bin/console app:passage-saison --to=2026-2027 --apply
 *   php bin/console app:passage-saison --to=2026-2027 --club-id=2 --apply
 */
#[AsCommand(
    name: 'app:passage-saison',
    description: 'Duplique les équipes vers la nouvelle saison et ré-affecte les joueuses par catégorie (date de naissance)',
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
        $from = (string) ($input->getOption('from') ?: (((int) explode('-', $to)[0] - 1) . '-' . ((int) explode('-', $to)[1] - 1)));
        $apply = (bool) $input->getOption('apply');
        $clubId = $input->getOption('club-id') !== null ? (int) $input->getOption('club-id') : null;

        $io->title(sprintf('Passage de saison %s → %s %s', $from, $to, $apply ? '(ÉCRITURE)' : '(DRY-RUN — rien ne sera écrit)'));

        // ── 1. Équipes sources ────────────────────────────────────────────
        $criteres = ['saison' => $from, 'isActive' => true];
        $equipesSources = $this->em->getRepository(Equipe::class)->findBy($criteres, ['nom' => 'ASC']);
        if ($clubId !== null) {
            $equipesSources = array_values(array_filter($equipesSources, fn(Equipe $e) => $e->getClub()?->getId() === $clubId));
        }
        if ($equipesSources === []) {
            $io->warning("Aucune équipe active trouvée pour la saison {$from}.");
            return Command::SUCCESS;
        }

        // Équipes déjà existantes en saison cible (idempotence)
        $equipesCibles = $this->em->getRepository(Equipe::class)->findBy(['saison' => $to]);
        $cibleParCle = [];
        foreach ($equipesCibles as $e) {
            $cibleParCle[$this->cleEquipe($e->getClub()?->getId(), $e->getNom())] = $e;
        }

        $nbCreees = 0;
        foreach ($equipesSources as $src) {
            $cle = $this->cleEquipe($src->getClub()?->getId(), $src->getNom());
            if (isset($cibleParCle[$cle])) {
                $io->text("  = Équipe déjà existante : {$src->getNom()} ({$to})");
                continue;
            }
            $nouvelle = new Equipe();
            $nouvelle->setClub($src->getClub());
            $nouvelle->setNom($src->getNom());
            $nouvelle->setCategorie($src->getCategorie());
            $nouvelle->setNiveau($src->getNiveau());
            $nouvelle->setSaison($to);
            $nouvelle->setIsActive(true);
            if ($apply) {
                $this->em->persist($nouvelle);
            }
            $cibleParCle[$cle] = $nouvelle;
            $nbCreees++;
            $io->text("  + Équipe créée : {$src->getNom()} · {$src->getCategorie()} ({$to})");
        }
        if ($apply) {
            $this->em->flush();
        }

        // Index équipes cibles par club puis catégorie (pour l'affectation)
        $parClubCategorie = [];
        foreach ($cibleParCle as $e) {
            $parClubCategorie[$e->getClub()?->getId()][strtoupper((string) $e->getCategorie())][] = $e;
        }

        // ── 2. Ré-affectation des joueuses ────────────────────────────────
        $joueuses = $this->em->getRepository(Joueur::class)->findBy(['isActive' => true, 'isTemporaire' => false], ['nom' => 'ASC']);
        if ($clubId !== null) {
            $joueuses = array_values(array_filter($joueuses, fn(Joueur $j) => $j->getClub()?->getId() === $clubId));
        }

        $jeRepo = $this->em->getRepository(JoueurEquipe::class);
        $affectees = 0;
        $aArbitrer = [];   // [nom, raison]
        $ambigues  = [];   // affectées mais plusieurs choix possibles

        foreach ($joueuses as $j) {
            $cidJ = $j->getClub()?->getId();
            $categorie = $this->calculator->categorie($j, $to);

            if ($categorie === null) {
                $aArbitrer[] = [$j->getNomComplet(), 'Pas de date de naissance — catégorie incalculable'];
                continue;
            }

            $equipe = $this->trouverEquipe($parClubCategorie[$cidJ] ?? [], $categorie, $j, $to);
            if ($equipe === null) {
                $aArbitrer[] = [$j->getNomComplet(), sprintf('Catégorie calculée %s — aucune équipe compatible en %s', $categorie, $to)];
                continue;
            }

            // Plusieurs équipes de même catégorie ? → signalé
            $nbMemeCat = count($parClubCategorie[$cidJ][strtoupper((string) $equipe->getCategorie())] ?? []);
            if ($nbMemeCat > 1) {
                $ambigues[] = [$j->getNomComplet(), $categorie, $equipe->getNom(), $nbMemeCat . ' équipes possibles'];
            }

            // Idempotence : affectation déjà existante ?
            $deja = $apply ? $jeRepo->findOneBy(['joueur' => $j, 'equipe' => $equipe, 'saison' => $to]) : null;
            if ($deja === null && $apply) {
                $je = new JoueurEquipe();
                $je->setJoueur($j);
                $je->setEquipe($equipe);
                $je->setSaison($to);
                $je->setType(JoueurEquipe::TYPE_PRINCIPALE);
                $this->em->persist($je);
                // Équipe de référence (rétrocompat Joueur.equipe)
                $j->setEquipe($equipe);
            }
            $affectees++;
            $io->text(sprintf('  → %s (%s) → %s', $j->getNomComplet(), $categorie, $equipe->getNom()));
        }

        if ($apply) {
            $this->em->flush();
        }

        // ── 3. Rapport ────────────────────────────────────────────────────
        $io->newLine();
        $io->success(sprintf(
            '%d équipe(s) créée(s), %d joueuse(s) affectée(s)%s',
            $nbCreees, $affectees, $apply ? '' : ' — DRY-RUN, relancer avec --apply pour écrire'
        ));
        if ($ambigues !== []) {
            $io->section('⚠ Affectations à VÉRIFIER (plusieurs équipes possibles — 1re choisie)');
            $io->table(['Joueuse', 'Catégorie', 'Équipe choisie', 'Détail'], $ambigues);
        }
        if ($aArbitrer !== []) {
            $io->section('✋ À traiter À LA MAIN');
            $io->table(['Joueuse', 'Raison'], $aArbitrer);
        }

        return Command::SUCCESS;
    }

    /**
     * Trouve l'équipe cible : catégorie exacte d'abord, sinon la plus
     * proche catégorie supérieure COMPATIBLE (surclassement doux).
     *
     * @param array<string, Equipe[]> $parCategorie équipes du club, indexées par catégorie (upper)
     */
    private function trouverEquipe(array $parCategorie, string $categorie, Joueur $j, string $saison): ?Equipe
    {
        // 1. Match exact (U15 → "U15") — tri alphabétique pour déterminisme
        $exact = $parCategorie[strtoupper($categorie)] ?? [];
        if ($exact !== []) {
            usort($exact, fn(Equipe $a, Equipe $b) => strcmp((string) $a->getNom(), (string) $b->getNom()));
            return $exact[0];
        }

        // 2. Sinon : équipes compatibles (âge ≤ borne) triées par borne croissante
        $candidates = [];
        foreach ($parCategorie as $cat => $equipes) {
            foreach ($equipes as $e) {
                if ($this->calculator->estCompatible($j, (string) $e->getCategorie(), $saison)) {
                    // Poids = borne numérique de la catégorie (Senior = 99)
                    $poids = preg_match('/^U(\d{1,2})/i', $cat, $m) ? (int) $m[1] : 99;
                    $candidates[] = [$poids, $e];
                }
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp((string) $a[1]->getNom(), (string) $b[1]->getNom()));
        return $candidates[0][1];
    }

    private function cleEquipe(?int $clubId, ?string $nom): string
    {
        return $clubId . '|' . mb_strtolower(trim((string) $nom));
    }
}
