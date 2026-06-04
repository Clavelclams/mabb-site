<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rencontre>
 */
class RencontreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rencontre::class);
    }

    /** Multi-tenant : ne retourne que les rencontre du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }
}
