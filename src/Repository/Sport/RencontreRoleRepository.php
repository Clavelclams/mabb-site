<?php

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\RencontreRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RencontreRole>
 */
class RencontreRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RencontreRole::class);
    }

    /**
     * Retourne les RencontreRole d'un user pour une liste de rencontres,
     * indexés par rencontre_id. Utilisé côté PIRB pour afficher les inscriptions
     * bénévoles de la joueuse sur son dashboard.
     *
     * @param Rencontre[] $rencontres
     * @return array<int, RencontreRole> indexé par rencontre_id
     */
    public function findByUserForRencontres(User $user, array $rencontres): array
    {
        if (empty($rencontres)) {
            return [];
        }

        $rows = $this->createQueryBuilder('rr')
            ->where('rr.user = :user')
            ->andWhere('rr.rencontre IN (:rencontres)')
            ->setParameter('user', $user)
            ->setParameter('rencontres', $rencontres)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $rr) {
            /** @var RencontreRole $rr */
            $map[$rr->getRencontre()->getId()] = $rr;
        }

        return $map;
    }
}
