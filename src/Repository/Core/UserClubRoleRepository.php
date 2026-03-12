<?php

namespace App\Repository\Core;

use App\Entity\Core\UserClubRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserClubRole>
 */
class UserClubRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserClubRole::class);
    }

    /**
     * Retourne tous les rôles actifs d'un user dans un club donné.
     * 
     * @return UserClubRole[]
     */
    public function findActiveRolesForUserInClub(int $userId, int $clubId): array
    {
        return $this->createQueryBuilder('ucr')
            ->andWhere('ucr.user = :userId')
            ->andWhere('ucr.club = :clubId')
            ->andWhere('ucr.isActive = true')
            ->setParameter('userId', $userId)
            ->setParameter('clubId', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Vérifie si un user a un rôle spécifique dans un club.
     */
    public function hasRole(int $userId, int $clubId, string $role): bool
    {
        $result = $this->createQueryBuilder('ucr')
            ->select('COUNT(ucr.id)')
            ->andWhere('ucr.user = :userId')
            ->andWhere('ucr.club = :clubId')
            ->andWhere('ucr.role = :role')
            ->andWhere('ucr.isActive = true')
            ->setParameter('userId', $userId)
            ->setParameter('clubId', $clubId)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }
}
