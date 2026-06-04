<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Equipe>
 */
class EquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipe::class);
    }

    /** Multi-tenant : ne retourne que les equipe du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }
}
