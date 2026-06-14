<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\BulletinScolaire;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BulletinScolaire>
 */
class BulletinScolaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BulletinScolaire::class);
    }

    /** @return BulletinScolaire[] */
    public function findForJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('b.anneeScolaire', 'DESC')
            ->addOrderBy('b.trimestre', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
