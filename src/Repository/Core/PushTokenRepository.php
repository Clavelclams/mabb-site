<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\PushToken;
use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushToken>
 */
class PushTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushToken::class);
    }

    public function findOneByToken(string $token): ?PushToken
    {
        return $this->findOneBy(['token' => $token]);
    }

    /** @return PushToken[] */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * Les jetons de plusieurs utilisateurs d'un coup : c'est ce dont on a besoin
     * pour prévenir toute une liste de convoquées en un seul appel à Expo.
     *
     * @param User[] $users
     * @return string[] les jetons bruts
     */
    public function jetonsPourUsers(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('p.token')
            ->where('p.user IN (:users)')
            ->setParameter('users', $users)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'token');
    }

    /** Supprime un jeton (déconnexion, ou appareil désinscrit côté Expo). */
    public function supprimerToken(string $token): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->where('p.token = :t')
            ->setParameter('t', $token)
            ->getQuery()
            ->execute();
    }
}
