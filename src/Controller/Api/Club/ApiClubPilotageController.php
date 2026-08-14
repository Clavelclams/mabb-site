<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\UserClubRole;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\CotisationJoueur;
use App\Entity\Sport\DossierLicence;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\NoteFrais;
use App\Entity\Sport\ParentJoueur;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-10 13/08/2026] Le PILOTAGE — la vue Direction de l'app.
 *
 * Ce qu'un dirigeant veut savoir en trente secondes, dans la file du
 * supermarché : où en est le club (effectif, licences payées) et surtout
 * QU'EST-CE QUI M'ATTEND — les demandes d'adhésion à valider, les liens
 * parents à confirmer, les candidatures bénévoles en souffrance, les notes
 * de frais à traiter. Chaque compteur non nul est une action à faire sur le
 * web ; l'app est le radar, le web reste le cockpit.
 *
 *   GET /api/club/pilotage → {effectif, licences, attentes, tresorerie?}
 *
 * ACCÈS : DIRIGEANT ou TRESORIER (ou super-admin). Le bloc trésorerie
 * (montants) n'est renvoyé qu'à eux — ce sont les mêmes règles que le web
 * (TresorerieVoter : le trésorier ET le dirigeant voient les finances).
 * Les compteurs RH (adhésions, parents) ne sont renvoyés qu'au DIRIGEANT :
 * un trésorier n'a pas à voir les demandes d'adhésion.
 *
 * Tous les agrégats sont des COUNT/SUM SQL : aucune donnée nominative ne
 * transite — des chiffres, pas des listes. Les listes restent sur le web.
 */
final class ApiClubPilotageController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SaisonService $saisonService,
    ) {}

    #[Route('/api/club/pilotage', name: 'api_club_pilotage', methods: ['GET'])]
    public function pilotage(Request $request): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerRole($club, [UserClubRole::ROLE_DIRIGEANT, UserClubRole::ROLE_TRESORIER]);

        $user = $this->utilisateur();
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || $this->estSuperAdmin($user);

        $saison = $this->saisonAvecEquipes($club, $this->saisonService->getSaisonActive());

        // ── Effectif : les forces vives ──────────────────────────────────
        $nbJoueuses = (int) $this->em->createQueryBuilder()
            ->select('COUNT(j.id)')
            ->from(Joueur::class, 'j')
            ->where('j.club = :club')->setParameter('club', $club)
            ->andWhere('j.isActive = true')
            ->andWhere('j.isTemporaire = false')
            ->getQuery()->getSingleScalarResult();

        $nbMembres = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(ucr.user))')
            ->from(UserClubRole::class, 'ucr')
            ->where('ucr.club = :club')->setParameter('club', $club)
            ->andWhere('ucr.status = :actif')->setParameter('actif', UserClubRole::STATUS_ACTIVE)
            ->andWhere('ucr.isActive = true')
            ->getQuery()->getSingleScalarResult();

        // ── Licences de la saison : payées / en attente ──────────────────
        $lignesLicences = $this->em->createQueryBuilder()
            ->select('d.paiementStatut AS statut, COUNT(d.id) AS nb')
            ->from(DossierLicence::class, 'd')
            ->where('d.club = :club')->setParameter('club', $club)
            ->andWhere('d.saison = :saison')->setParameter('saison', $saison)
            ->groupBy('d.paiementStatut')
            ->getQuery()->getArrayResult();
        $licences = ['total' => 0, 'payees' => 0, 'enAttente' => 0];
        foreach ($lignesLicences as $l) {
            $nb = (int) $l['nb'];
            $licences['total'] += $nb;
            if (in_array($l['statut'], [DossierLicence::PAIEMENT_PAYE, DossierLicence::PAIEMENT_EXONERE], true)) {
                $licences['payees'] += $nb;
            } else {
                $licences['enAttente'] += $nb;
            }
        }

        $reponse = [
            'saison'   => $saison,
            'effectif' => [
                'joueuses' => $nbJoueuses,
                'membres'  => $nbMembres,
            ],
            'licences' => $licences,
        ];

        // ── Les ATTENTES : chaque compteur non nul est un geste à faire ──
        // Réservées au dirigeant : c'est SON panier de validation.
        if ($estDirigeant) {
            $reponse['attentes'] = [
                'adhesions' => (int) $this->em->createQueryBuilder()
                    ->select('COUNT(ucr.id)')
                    ->from(UserClubRole::class, 'ucr')
                    ->where('ucr.club = :club')->setParameter('club', $club)
                    ->andWhere('ucr.status = :pending')->setParameter('pending', UserClubRole::STATUS_PENDING)
                    ->getQuery()->getSingleScalarResult(),

                'liensParents' => (int) $this->em->createQueryBuilder()
                    ->select('COUNT(pj.id)')
                    ->from(ParentJoueur::class, 'pj')
                    ->join('pj.joueur', 'j')
                    ->where('j.club = :club')->setParameter('club', $club)
                    ->andWhere('pj.statut = :pending')->setParameter('pending', ParentJoueur::STATUT_PENDING)
                    ->getQuery()->getSingleScalarResult(),

                'candidaturesOtm' => (int) $this->em->createQueryBuilder()
                    ->select('COUNT(a.id)')
                    ->from(AffectationMatch::class, 'a')
                    ->join('a.rencontre', 'r')
                    ->where('r.club = :club')->setParameter('club', $club)
                    ->andWhere('a.statut = :candidat')->setParameter('candidat', AffectationMatch::STATUT_CANDIDAT)
                    ->andWhere('r.date >= :auj')->setParameter('auj', new \DateTimeImmutable('today'))
                    ->getQuery()->getSingleScalarResult(),
            ];
        }

        // ── Trésorerie : dirigeant ET trésorier (mêmes droits que le web) ─
        $lignesCotis = $this->em->createQueryBuilder()
            ->select('SUM(c.montantAttendu) AS attendu, SUM(c.montantPaye) AS paye, COUNT(c.id) AS nb')
            ->from(CotisationJoueur::class, 'c')
            ->join('c.joueur', 'j')
            ->where('j.club = :club')->setParameter('club', $club)
            ->andWhere('c.saison = :saison')->setParameter('saison', $saison)
            ->andWhere('c.statut != :exemptee')->setParameter('exemptee', CotisationJoueur::STATUT_EXEMPTEE)
            ->getQuery()->getOneOrNullResult();

        $reponse['tresorerie'] = [
            'cotisations' => [
                'attendu' => (float) ($lignesCotis['attendu'] ?? 0),
                'encaisse' => (float) ($lignesCotis['paye'] ?? 0),
                'nb'       => (int) ($lignesCotis['nb'] ?? 0),
            ],
            'notesFraisEnAttente' => (int) $this->em->createQueryBuilder()
                ->select('COUNT(n.id)')
                ->from(NoteFrais::class, 'n')
                ->where('n.club = :club')->setParameter('club', $club)
                ->andWhere('n.statut = :attente')->setParameter('attente', NoteFrais::STATUT_EN_ATTENTE)
                ->getQuery()->getSingleScalarResult(),
        ];

        return new JsonResponse($reponse);
    }
}
