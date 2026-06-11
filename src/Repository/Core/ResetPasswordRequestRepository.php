<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\ResetPasswordRequest;
use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResetPasswordRequest>
 */
class ResetPasswordRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResetPasswordRequest::class);
    }

    /**
     * Trouve une demande VALIDE (non-expirée, non-consommée) par hash de token.
     * Retourne null si le token est invalide, expiré, déjà consommé ou inconnu.
     */
    public function findValidByTokenHash(string $tokenHash): ?ResetPasswordRequest
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('r')
            ->where('r.tokenHash = :h')
            ->andWhere('r.expiresAt > :now')
            ->andWhere('r.consumedAt IS NULL')
            ->setParameter('h', $tokenHash)
            ->setParameter('now', $now)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Supprime toutes les demandes actives d'un user (pour éviter le spam
     * et garantir qu'à tout instant il n'y a qu'UN seul token valide par user).
     */
    public function deleteAllForUser(User $user): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.user = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Cleanup : supprime les demandes expirées de plus de 24h.
     * À appeler périodiquement (cron quotidien) pour ne pas faire grossir
     * la table indéfiniment.
     */
    public function purgeExpired(): int
    {
        $threshold = (new \DateTimeImmutable())->modify('-24 hours');

        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.expiresAt < :t')
            ->setParameter('t', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Compte les demandes faites par une IP sur les N dernières minutes.
     * Sert au rate-limit anti-brute-force dans le service.
     */
    public function countRecentByIp(string $ip, int $minutes = 15): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$minutes} minutes");

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requestIp = :ip')
            ->andWhere('r.requestedAt > :t')
            ->setParameter('ip', $ip)
            ->setParameter('t', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
