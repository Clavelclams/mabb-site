<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\AffectationMatchRepository;
use App\Service\Otm\OtmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * [OTM V2 — 12/07/2026] La clôture du mercredi soir.
 *
 * Les inscriptions au week-end ouvrent 7 jours avant la rencontre et ferment
 * le mercredi 23h59. Passé ce délai, les postes de TITULAIRE encore vides sont
 * attribués AU HASARD parmi le staff. « Si tu ne t'es pas placé où tu voulais,
 * tant pis, on te place. »
 *
 * RÈGLES RESPECTÉES (toutes déléguées à OtmService, source unique) :
 *   - on ne pioche que dans le STAFF (les bénévoles/parents ne sont jamais
 *     placés d'office — ils s'inscrivent s'ils veulent) ;
 *   - on ne place jamais quelqu'un sur un poste qui lui est INTERDIT ;
 *   - pas plus de 2× le même poste dans la même journée ;
 *   - une personne ne tient qu'UN poste par rencontre.
 *
 * Seules les rencontres à DOMICILE sont concernées (c'est le club recevant qui
 * fournit la table de marque).
 *
 * Le dirigeant peut évidemment tout réajuster ensuite dans le Manager.
 *
 * IDEMPOTENTE : ne remplit que les trous. Relançable sans risque.
 *
 * USAGE :
 *   php bin/console app:otm:cloturer              (simulation, n'écrit rien)
 *   php bin/console app:otm:cloturer --execute    (applique)
 *   php bin/console app:otm:cloturer --execute --postes=DELEGUE,CHRONO,EMARQUE
 *
 * CRON (chaque nuit — la commande ne fait rien tant que la fenêtre est ouverte) :
 *   30 2 * * *  cd ~/mabb-site && php bin/console app:otm:cloturer --execute
 */
#[AsCommand(
    name: 'app:otm:cloturer',
    description: 'Clôture les inscriptions du week-end et place le staff manquant au hasard.'
)]
final class OtmCloturerCommand extends Command
{
    /**
     * Postes remplis d'office par défaut : la table de marque.
     * On NE remplit PAS l'arbitrage d'office (ça demande une qualification),
     * ni la buvette / Stats Live / responsable de salle (optionnels).
     * → surchargeable avec --postes.
     */
    private const POSTES_PAR_DEFAUT = [
        AffectationMatch::ROLE_DELEGUE,
        AffectationMatch::ROLE_CHRONO,
        AffectationMatch::ROLE_EMARQUE,
        AffectationMatch::ROLE_OPERATEUR,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationMatchRepository $affectationRepo,
        private readonly OtmService $otm,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Écrit réellement en base (sinon simulation).')
            ->addOption('postes', null, InputOption::VALUE_REQUIRED,
                'Postes à pourvoir d\'office, séparés par des virgules. Défaut : '
                . implode(',', self::POSTES_PAR_DEFAUT));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');

        $postes = self::POSTES_PAR_DEFAUT;
        if ($opt = $input->getOption('postes')) {
            $postes = array_values(array_filter(array_map('trim', explode(',', (string) $opt))));
            foreach ($postes as $p) {
                if (!isset(AffectationMatch::ROLES[$p])) {
                    $io->error("Poste inconnu : $p. Valides : " . implode(', ', array_keys(AffectationMatch::ROLES)));

                    return Command::FAILURE;
                }
            }
        }

        $io->title('Clôture OTM' . ($execute ? '' : ' (SIMULATION)'));
        $io->text('Postes pourvus d\'office : ' . implode(', ', $postes));

        // Rencontres à venir, à domicile (c'est nous qui tenons la table).
        $rencontres = $this->em->getRepository(Rencontre::class)->createQueryBuilder('r')
            ->andWhere('r.date > :now')->setParameter('now', new \DateTimeImmutable())
            ->andWhere('r.domicile = true')
            ->orderBy('r.date', 'ASC')
            ->getQuery()->getResult();

        $stats = ['rencontres' => 0, 'places' => 0, 'sans_candidat' => 0, 'ignorees_fenetre' => 0];
        $lignes = [];

        foreach ($rencontres as $rencontre) {
            /** @var Rencontre $rencontre */
            $fenetre = $this->otm->fenetre($rencontre);

            // Tant que la fenêtre n'est pas fermée, on ne touche à rien :
            // les gens ont encore le droit de se placer eux-mêmes.
            if (!$fenetre['fermee']) {
                $stats['ignorees_fenetre']++;
                continue;
            }

            $club = $rencontre->getClub();
            if ($club === null) {
                continue;
            }

            $pool = $this->poolStaff($club->getId());
            if ($pool === []) {
                continue;
            }

            $traitee = false;

            foreach ($postes as $poste) {
                // Poste déjà tenu par un titulaire ? on passe.
                $actif = $this->affectationRepo->findActiveByRencontreAndRole($rencontre, $poste);
                if ($actif !== null && $actif->isTitulaire()) {
                    continue;
                }

                // On mélange : c'est un tirage au sort, pas un classement.
                $candidats = $pool;
                shuffle($candidats);

                $choisi = null;
                foreach ($candidats as $candidat) {
                    // Une personne ne tient qu'UN poste par rencontre.
                    if ($this->dejaSurCetteRencontre($rencontre, $candidat)) {
                        continue;
                    }
                    // Interdictions + anti-répétition + fenêtre (ignorée : parAdmin).
                    if ($this->otm->motifRefus($rencontre, $candidat, $poste, false, true) !== null) {
                        continue;
                    }
                    $choisi = $candidat;
                    break;
                }

                if ($choisi === null) {
                    $stats['sans_candidat']++;
                    $lignes[] = [
                        $rencontre->getDate()?->format('d/m H:i') ?? '?',
                        (string) $rencontre->getAdversaire(),
                        AffectationMatch::ROLES[$poste],
                        '⚠️ personne de dispo',
                    ];
                    continue;
                }

                $a = (new AffectationMatch())
                    ->setRencontre($rencontre)
                    ->setUser($choisi)
                    ->setRole($poste)
                    ->setEstAssistant(false)
                    ->setStatut(AffectationMatch::STATUT_ASSIGNE)
                    ->setNote('Placé automatiquement à la clôture du mercredi.');

                if ($execute) {
                    $this->em->persist($a);
                    // flush immédiat : les règles (anti-répétition, « déjà sur
                    // cette rencontre ») doivent voir ce qu'on vient d'écrire.
                    $this->em->flush();
                }

                $stats['places']++;
                $traitee = true;
                $lignes[] = [
                    $rencontre->getDate()?->format('d/m H:i') ?? '?',
                    (string) $rencontre->getAdversaire(),
                    AffectationMatch::ROLES[$poste],
                    $choisi->getPrenom() . ' ' . $choisi->getNom(),
                ];
            }

            if ($traitee) {
                $stats['rencontres']++;
            }
        }

        if ($lignes !== []) {
            $io->table(['Date', 'Adversaire', 'Poste', 'Placé'], $lignes);
        } else {
            $io->text('Rien à pourvoir.');
        }

        $io->table(['Métrique', 'Valeur'], [
            ['Rencontres traitées', $stats['rencontres']],
            ['Postes pourvus d\'office', $stats['places']],
            ['Postes sans personne dispo', $stats['sans_candidat']],
            ['Rencontres encore ouvertes (intactes)', $stats['ignorees_fenetre']],
        ]);

        if ($execute) {
            $io->success('Clôture appliquée. Le dirigeant peut réajuster dans le Manager.');
        } else {
            $io->note('SIMULATION : rien écrit. Relance avec --execute pour appliquer.');
        }

        return Command::SUCCESS;
    }

    /**
     * Le vivier : le STAFF du club (services civiques inclus, ils sont
     * rattachés au rôle STAFF). Les bénévoles/parents/joueuses n'y sont PAS :
     * on ne leur impose jamais un poste.
     *
     * @return list<User>
     */
    private function poolStaff(int $clubId): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')
            ->distinct()
            ->from(User::class, 'u')
            ->join(UserClubRole::class, 'ucr', 'WITH', 'ucr.user = u')
            ->andWhere('ucr.club = :club')->setParameter('club', $clubId)
            ->andWhere('ucr.role IN (:roles)')->setParameter('roles', OtmService::ROLES_POOL_AUTO)
            ->andWhere('ucr.isActive = true')
            ->andWhere('ucr.status = :actif')->setParameter('actif', UserClubRole::STATUS_ACTIVE)
            ->andWhere('u.isActive = true')
            ->getQuery()->getResult();
    }

    /** Cette personne tient-elle déjà un poste sur cette rencontre ? */
    private function dejaSurCetteRencontre(Rencontre $rencontre, User $user): bool
    {
        foreach ($this->affectationRepo->findByRencontre($rencontre) as $parRole) {
            foreach ($parRole as $a) {
                /** @var AffectationMatch $a */
                if ($a->isCouvert() && $a->getUser()?->getId() === $user->getId()) {
                    return true;
                }
            }
        }

        return false;
    }
}
