<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\TirFfbb;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TirFfbb>
 */
class TirFfbbRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TirFfbb::class);
    }

    /** @return TirFfbb[] */
    public function findForRencontre(Rencontre $rencontre, ?string $source = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.joueur', 'j')->addSelect('j')
            ->where('t.rencontre = :r')
            ->setParameter('r', $rencontre);

        if ($source) {
            $qb->andWhere('t.source = :s')->setParameter('s', $source);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return TirFfbb[] */
    public function findForJoueur(Joueur $joueur, ?string $source = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('t.createdAt', 'DESC');

        if ($source) {
            $qb->andWhere('t.source = :s')->setParameter('s', $source);
        }

        return $qb->getQuery()->getResult();
    }
}
