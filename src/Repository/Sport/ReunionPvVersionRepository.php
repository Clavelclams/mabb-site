<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionPvVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReunionPvVersion>
 */
class ReunionPvVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReunionPvVersion::class);
    }

    /**
     * Toutes les versions du PV d'une réunion, plus récentes d'abord.
     * @return ReunionPvVersion[]
     */
    public function findByReunion(Reunion $reunion): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.reunion = :r')
            ->setParameter('r', $reunion)
            ->orderBy('v.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
