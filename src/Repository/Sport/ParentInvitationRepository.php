<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\ParentInvitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParentInvitation>
 */
class ParentInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentInvitation::class);
    }

    public function findValidByTokenHash(string $tokenHash): ?ParentInvitation
    {
        return $this->createQueryBuilder('pi')
            ->where('pi.tokenHash = :h')
            ->andWhere('pi.acceptedAt IS NULL')
            ->andWhere('pi.expiresAt > :now')
            ->setParameter('h', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Anti-spam : compter les invits envoyées sur cet email dans les dernières 24h.
     */
    public function countRecentByEmail(string $email, int $hours = 24): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$hours} hours");
        return (int) $this->createQueryBuilder('pi')
            ->select('COUNT(pi.id)')
            ->where('pi.emailCible = :e')
            ->andWhere('pi.createdAt > :t')
            ->setParameter('e', strtolower(trim($email)))
            ->setParameter('t', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
