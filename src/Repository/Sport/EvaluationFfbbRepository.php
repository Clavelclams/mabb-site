<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\EvaluationFfbb;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationFfbb>
 */
class EvaluationFfbbRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationFfbb::class);
    }

    /** @return EvaluationFfbb[] */
    public function findForRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.joueur', 'j')->addSelect('j')
            ->where('e.rencontre = :r')
            ->setParameter('r', $rencontre)
            ->orderBy('e.points', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findForJoueurEtRencontre(Joueur $joueur, int $rencontreId): ?EvaluationFfbb
    {
        return $this->createQueryBuilder('e')
            ->where('e.joueur = :j')
            ->andWhere('IDENTITY(e.rencontre) = :r')
            ->setParameter('j', $joueur)
            ->setParameter('r', $rencontreId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
