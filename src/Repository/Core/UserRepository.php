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
     * rolesMembre est un champ JSON → on utilise JSON_CONTAINS pour MySQL.
     */
    public function findPublicMembers(?string $role = null): array
    {
        if ($role) {
            // JSON_CONTAINS(roles_membre, '"coach"') → true si le tableau contient la valeur
            return $this->getEntityManager()
                ->createQuery(
                    'SELECT u FROM App\Entity\Core\User u
                     WHERE u.isPublic = :public
                     AND JSON_CONTAINS(u.rolesMembre, :role) = 1
                     ORDER BY u.prenom ASC'
                )
                ->setParameter('public', true)
                ->setParameter('role', json_encode($role))
                ->getResult();
        }

        return $this->createQueryBuilder('u')
            ->where('u.isPublic = :public')
            ->setParameter('public', true)
            ->orderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
