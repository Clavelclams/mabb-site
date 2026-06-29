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

    /**
     * Compte les rôles remplis par rencontre, en une seule requête SQL.
     * Évite le problème N+1 sur la liste des rencontres.
     *
     * Pourquoi une seule requête ?
     *   Si on laisse Twig faire `r.roles|length` sur une liste de 20 rencontres,
     *   Doctrine lazy-load la collection pour chacune → 20 requêtes SQL.
     *   Ici : 1 requête SELECT COUNT(*) GROUP BY rencontre_id pour toutes.
     *
     * @param Rencontre[] $rencontres
     * @return array<int, int>  [rencontre_id => nb_roles_remplis]
     */
    public function countByRencontres(array $rencontres): array
    {
        if (empty($rencontres)) {
            return [];
        }

        // IDENTITY() = accès à la FK brute (rencontre_id) sans JOIN
        $rows = $this->createQueryBuilder('rr')
            ->select('IDENTITY(rr.rencontre) AS rencontre_id, COUNT(rr.id) AS nb')
            ->where('rr.rencontre IN (:rencontres)')
            ->setParameter('rencontres', $rencontres)
            ->groupBy('rr.rencontre')
            ->getQuery()
            ->getScalarResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['rencontre_id']] = (int) $row['nb'];
        }

        return $map;
    }
}
