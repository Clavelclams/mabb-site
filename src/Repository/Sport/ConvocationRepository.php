<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Convocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Convocation>
 */
class ConvocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convocation::class);
    }

    /** Multi-tenant : ne retourne que les convocation du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }
}
