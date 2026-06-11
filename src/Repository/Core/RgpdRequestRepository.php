<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RgpdRequest>
 */
class RgpdRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RgpdRequest::class);
    }

    /** @return RgpdRequest[] */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')->addSelect('u')
            ->where('r.statut = :s')
            ->setParameter('s', RgpdRequest::STATUT_PENDING)
            ->orderBy('r.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return RgpdRequest[] */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')->addSelect('u')
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findActivePendingForUser(User $user): ?RgpdRequest
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :u')
            ->andWhere('r.statut = :s')
            ->setParameter('u', $user)
            ->setParameter('s', RgpdRequest::STATUT_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
