<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\SeanceTir;
use App\Entity\Sport\ZoneTir;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZoneTir>
 *
 * Note : la plupart des queries sur les zones passent par SeanceTirRepository
 * (fetch avec eager-load des zones via leftJoin). Ce repository est surtout utile
 * pour les agrégations directes sur les zones (shot map d'un club, etc.).
 */
class ZoneTirRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZoneTir::class);
    }

    /**
     * Toutes les zones d'une séance — déjà disponibles via SeanceTir::getZones(),
     * mais utile si on veut les charger à la demande.
     *
     * @return ZoneTir[]
     */
    public function findBySeance(SeanceTir $seance): array
    {
        return $this->findBy(['seanceTir' => $seance]);
    }

    /**
     * Agrégat de toutes les zones d'une joueuse par type de tir.
     * Utilisé pour le résumé global dans le profil PIRB.
     *
     * @param int $joueurId
     * @param bool $validatedOnly si true, ignore les séances non validées coach
     * @return array<string, array{tentatives: int, reussis: int, pct: float|null}>
     */
    public function aggregateByTypeTir(int $joueurId, bool $validatedOnly = true): array
    {
        $qb = $this->createQueryBuilder('z')
            ->select(
                'z.typeTir as typeTir',
                'SUM(z.tentatives) as tentatives',
                'SUM(z.reussis)    as reussis'
            )
            ->join('z.seanceTir', 'st')
            ->where('st.joueur = :joueurId')
            ->setParameter('joueurId', $joueurId)
            ->groupBy('z.typeTir');

        if ($validatedOnly) {
            $qb->andWhere('st.validatedByCoach = true');
        }

        $rows = $qb->getQuery()->getArrayResult();

        $result = [];
        foreach (SeanceTir::TYPES_TIR as $type) {
            $result[$type] = ['tentatives' => 0, 'reussis' => 0, 'pct' => null];
        }
        foreach ($rows as $row) {
            $t = (int) $row['tentatives'];
            $r = (int) $row['reussis'];
            $result[$row['typeTir']] = [
                'tentatives' => $t,
                'reussis'    => $r,
                'pct'        => $t > 0 ? round($r / $t * 100, 1) : null,
            ];
        }

        return $result;
    }
}
