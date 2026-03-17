<?php

namespace App\Repository\Core;

use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Mise à jour automatique du hash de mot de passe (rehash si algo change).
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Trouve un user actif par email.
     */
    public function findActiveByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->andWhere('u.isActive = true')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les membres dont le profil est public, filtrables par rôle.
     */
    public function findPublicMembers(?string $role = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.isPublic = :public')
            ->setParameter('public', true)
            ->orderBy('u.roleMembre', 'ASC')
            ->addOrderBy('u.prenom', 'ASC');

        if ($role) {
            $qb->andWhere('u.roleMembre = :role')
               ->setParameter('role', $role);
        }

        return $qb->getQuery()->getResult();
    }
}
