<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Evenement;
use App\Entity\Sport\InscriptionSortie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InscriptionSortie>
 */
class InscriptionSortieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InscriptionSortie::class);
    }

    /**
     * Les inscriptions d'une sortie, la plus récente d'abord.
     *
     * @return InscriptionSortie[]
     */
    public function findByEvenement(Evenement $evenement): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.evenement = :ev')
            ->setParameter('ev', $evenement)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
