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

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

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
     * Retourne les membres dont le profil est public.
     * Le filtrage par rôle se fait en PHP (pas JSON_CONTAINS — compatibilité MariaDB).
     */
    public function findPublicMembers(?string $role = null): array
    {
        // On récupère tous les membres publics depuis la BDD
        $membres = $this->createQueryBuilder('u')
            ->where('u.isPublic = :public')
            ->setParameter('public', true)
            ->orderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        // Filtrage PHP par rôle si demandé
        if ($role) {
            $membres = array_filter($membres, fn(User $u) => $u->hasRoleMembre($role));
        }

        return array_values($membres);
    }
}
