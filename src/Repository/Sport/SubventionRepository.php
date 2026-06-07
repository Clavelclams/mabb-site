<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Subvention;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subvention>
 */
class SubventionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subvention::class);
    }

    /**
     * Toutes les subventions d'un club pour une saison, triées par statut puis date.
     *
     * @return Subvention[]
     */
    public function findByClubAndSaison(Club $club, string $saison): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.club = :club')
            ->andWhere('s.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            // Tri logique : actives d'abord, finalisées ensuite
            ->addSelect("CASE
                WHEN s.statut = 'EN_PREPARATION' THEN 0
                WHEN s.statut = 'DEPOSEE' THEN 1
                WHEN s.statut = 'ACCORDEE' THEN 2
                WHEN s.statut = 'TOUCHEE' THEN 3
                ELSE 4
            END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('s.dateDepot', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compteurs par statut pour les KPI dashboard.
     *
     * @return array<string, int>
     */
    public function countByStatutForClubAndSaison(Club $club, string $saison): array
    {
        $result = $this->createQueryBuilder('s')
            ->select('s.statut AS statut, COUNT(s.id) AS nb')
            ->where('s.club = :club')
            ->andWhere('s.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->groupBy('s.statut')
            ->getQuery()
            ->getResult();

        $counts = array_fill_keys(Subvention::STATUTS, 0);
        foreach ($result as $row) {
            $counts[$row['statut']] = (int) $row['nb'];
        }
        return $counts;
    }

    /**
     * Totaux globaux pour la saison.
     *   - demande : somme des montants demandés (toutes subventions hors REJETEE)
     *   - accorde : somme des montants accordés (subventions ACCORDEE ou TOUCHEE)
     *   - touche  : somme des montants effectivement reçus (TOUCHEE seulement)
     *
     * @return array{demande: string, accorde: string, touche: string}
     */
    public function getTotauxForClubAndSaison(Club $club, string $saison): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.statut AS statut',
                'COALESCE(SUM(s.montantDemande), 0) AS d',
                'COALESCE(SUM(s.montantAccorde), 0) AS a',
                'COALESCE(SUM(s.montantTouche), 0) AS t'
            )
            ->where('s.club = :club')
            ->andWhere('s.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->groupBy('s.statut')
            ->getQuery()
            ->getResult();

        $totDemande = '0.00';
        $totAccorde = '0.00';
        $totTouche  = '0.00';

        foreach ($rows as $r) {
            // Demandé : on agrège partout sauf REJETEE (perdue d'avance)
            if ($r['statut'] !== Subvention::STATUT_REJETEE) {
                $totDemande = bcadd($totDemande, (string) $r['d'], 2);
            }
            // Accordé : pour ACCORDEE + TOUCHEE
            if (in_array($r['statut'], [Subvention::STATUT_ACCORDEE, Subvention::STATUT_TOUCHEE], true)) {
                $totAccorde = bcadd($totAccorde, (string) $r['a'], 2);
            }
            // Touché : que pour TOUCHEE
            if ($r['statut'] === Subvention::STATUT_TOUCHEE) {
                $totTouche = bcadd($totTouche, (string) $r['t'], 2);
            }
        }

        return [
            'demande' => $totDemande,
            'accorde' => $totAccorde,
            'touche'  => $totTouche,
        ];
    }
}
