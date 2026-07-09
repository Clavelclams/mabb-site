<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\InscriptionSortie;
use App\Service\DechargeSortieUploader;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purge RGPD des inscriptions aux sorties (Lot D, doc 23 §8 / ADR-0011 / RGPD-0010).
 *
 * POURQUOI : les inscriptions portent des données personnelles de MINEURES
 * (nom, prénom, date de naissance, responsable légal, téléphone), y compris
 * de non-licenciées extérieures au club. Base légale = gestion de la sortie ;
 * une fois la saison terminée, cette finalité est éteinte → on doit MINIMISER.
 *
 * CE QUE FAIT LA COMMANDE (anonymisation, pas suppression) :
 *   - efface l'identité de saisie libre : nom → « Anonymisé », prénom,
 *     date de naissance, responsable légal, téléphone, commentaire → null ;
 *   - efface la référence à la décharge signée (autorisationFichier) et
 *     signale le fichier physique à supprimer s'il existe ;
 *   - CONSERVE la ligne avec présence / statut de paiement / montant :
 *     les agrégats (dashboard sorties, bilans, comptabilité) restent justes.
 *   - CONSERVE le lien vers la fiche Joueur (licenciée) : ces données vivent
 *     dans le SI club et relèvent du cycle de vie licenciée (RGPD-0008),
 *     pas de celui des sorties.
 *
 * GARDE-FOUS :
 *   - dry-run PAR DÉFAUT : rien n'est modifié sans --execute ;
 *   - ne purge JAMAIS la saison active ni le futur (uniquement < 1er juillet
 *     de la saison active, la même bascule que SaisonService) ;
 *   - idempotente : les lignes déjà anonymisées sont ignorées.
 *
 * USAGE :
 *   php bin/console app:sorties:purger-rgpd                 # aperçu (dry-run)
 *   php bin/console app:sorties:purger-rgpd --execute       # purge réelle
 *   php bin/console app:sorties:purger-rgpd --saison=2025-2026 --execute
 *
 * À planifier en cron chaque été (ex. 15 juillet) — cf. RGPD-0003.
 */
#[AsCommand(
    name: 'app:sorties:purger-rgpd',
    description: 'Anonymise les inscriptions aux sorties des saisons terminées (RGPD, données de mineures).',
)]
class PurgerInscriptionsSortiesCommand extends Command
{
    private const MARQUEUR_ANONYME = 'Anonymisé';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SaisonService $saisonService,
        private readonly DechargeSortieUploader $dechargeUploader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('saison', null, InputOption::VALUE_REQUIRED,
                'Saison à purger (ex: 2025-2026). Défaut : toutes les saisons terminées.')
            ->addOption('execute', null, InputOption::VALUE_NONE,
                'Applique réellement la purge (sans ce flag : dry-run, rien n\'est modifié).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $saison  = $input->getOption('saison') !== null ? trim((string) $input->getOption('saison')) : null;

        // Borne de sécurité : début de la saison COURANTE (bascule 1er juillet,
        // même règle que SaisonService). Tout ce qui est >= cette date est
        // intouchable. NB : getSaisonCourante() et pas getSaisonActive() —
        // cette dernière lit la SESSION, qui n'existe pas en console.
        $saisonActive = $this->saisonService->getSaisonCourante();
        $anneeActive  = (int) explode('-', $saisonActive)[0];
        $debutSaisonActive = new \DateTimeImmutable($anneeActive . '-07-01 00:00:00');

        if ($saison !== null) {
            if (!$this->saisonService->isValide($saison)) {
                $io->error("Saison invalide : « $saison » (format attendu : 2025-2026).");
                return Command::FAILURE;
            }
            $anneeDebut = (int) explode('-', $saison)[0];
            $borneMin = new \DateTimeImmutable($anneeDebut . '-07-01 00:00:00');
            $borneMax = $borneMin->modify('+1 year');
            if ($borneMax > $debutSaisonActive) {
                $io->error("Refus : « $saison » n'est pas une saison terminée (active : $saisonActive). On ne purge jamais la saison en cours.");
                return Command::FAILURE;
            }
        } else {
            // Toutes les saisons terminées : de l'origine au début de la saison active.
            $borneMin = new \DateTimeImmutable('2000-01-01 00:00:00');
            $borneMax = $debutSaisonActive;
        }

        // Inscriptions dont l'ÉVÉNEMENT est dans la fenêtre et qui portent
        // encore au moins une donnée personnelle de saisie libre.
        /** @var InscriptionSortie[] $inscriptions */
        $inscriptions = $this->em->createQueryBuilder()
            ->select('i', 'e')
            ->from(InscriptionSortie::class, 'i')
            ->join('i.evenement', 'e')
            ->andWhere('e.date >= :min')->setParameter('min', $borneMin)
            ->andWhere('e.date < :max')->setParameter('max', $borneMax)
            ->orderBy('e.date', 'ASC')
            ->getQuery()->getResult();

        $aPurger = array_filter($inscriptions, fn (InscriptionSortie $i) => !$this->estDejaAnonymisee($i));

        $io->title(sprintf(
            '%s — purge RGPD des inscriptions sorties (avant le %s)',
            $execute ? 'EXÉCUTION' : 'DRY-RUN (aperçu, rien ne sera modifié)',
            $borneMax->format('d/m/Y')
        ));
        $io->writeln(sprintf('Inscriptions dans la fenêtre : %d — dont à anonymiser : %d', count($inscriptions), count($aPurger)));

        if ($aPurger === []) {
            $io->success('Rien à purger : tout est déjà anonymisé (ou aucune inscription).');
            return Command::SUCCESS;
        }

        $fichiersAsupprimer = [];
        foreach ($aPurger as $i) {
            $evt = $i->getEvenement();
            $io->writeln(sprintf(
                '  • #%d  %s — « %s » du %s  (club %s)',
                $i->getId(),
                $i->getNomAffichage() !== '' ? $i->getNomAffichage() : '(sans nom)',
                $evt?->getTitre() ?? '?',
                $evt?->getDate()?->format('d/m/Y') ?? '?',
                $evt?->getClub()?->getNom() ?? '?'
            ));

            if ($i->getAutorisationFichier() !== null) {
                $fichiersAsupprimer[] = $i->getAutorisationFichier();
                if ($execute) {
                    // Suppression PHYSIQUE de la décharge signée (var/decharges/),
                    // avant d'effacer la référence en base.
                    $this->dechargeUploader->delete($i);
                }
            }

            if ($execute) {
                $i->setNom(self::MARQUEUR_ANONYME)
                    ->setPrenom(null)
                    ->setDateNaissance(null)
                    ->setResponsableLegal(null)
                    ->setTelephoneContact(null)
                    ->setCommentaire(null)
                    ->setAutorisationFichier(null);
            }
        }

        if ($execute) {
            $this->em->flush();
            $io->success(sprintf('%d inscription(s) anonymisée(s).', count($aPurger)));
        } else {
            $io->note(sprintf('Dry-run : %d inscription(s) SERAIENT anonymisées. Relance avec --execute pour appliquer.', count($aPurger)));
        }

        if ($fichiersAsupprimer !== []) {
            if ($execute) {
                $io->writeln(sprintf('%d décharge(s) signée(s) supprimée(s) du stockage (var/decharges/).', count($fichiersAsupprimer)));
            } else {
                $io->note(sprintf(
                    "%d décharge(s) signée(s) SERAIENT supprimées du stockage :\n  %s",
                    count($fichiersAsupprimer),
                    implode("\n  ", $fichiersAsupprimer)
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Une inscription est considérée anonymisée si l'identité de saisie libre
     * est vide ou déjà marquée. (Le lien Joueur, lui, est conservé par design.)
     */
    private function estDejaAnonymisee(InscriptionSortie $i): bool
    {
        $identiteLibreVide = ($i->getNom() === null || $i->getNom() === self::MARQUEUR_ANONYME)
            && $i->getPrenom() === null
            && $i->getDateNaissance() === null
            && $i->getResponsableLegal() === null
            && $i->getTelephoneContact() === null;

        return $identiteLibreVide
            && $i->getCommentaire() === null
            && $i->getAutorisationFichier() === null;
    }
}
