<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\ApiToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /** Retrouve un jeton VALIDE (non expiré) depuis sa valeur en clair. */
    public function findValide(string $tokenClair): ?ApiToken
    {
        $token = $this->findOneBy(['tokenHash' => ApiToken::hashDe($tokenClair)]);
        if ($token === null || $token->estExpire()) {
            return null;
        }
        return $token;
    }

    /** Purge les jetons expirés (appelable par cron/commande plus tard). */
    public function purgerExpires(): int
    {
        return $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
