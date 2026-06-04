<?php

namespace App\Repository\Sport;

use App\Entity\Sport\PlanningSeance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningSeance>
 */
class PlanningSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSeance::class);
    }

    /**
     * Plannings actifs d'une équipe, triés par jour puis heure pour affichage.
     */
    public function findActifsByEquipe(int $equipeId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.equipe = :equipe')
            ->andWhere('p.isActive = true')
            ->setParameter('equipe', $equipeId)
            ->orderBy('p.jourSemaine', 'ASC')
            ->addOrderBy('p.heureDebut', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
