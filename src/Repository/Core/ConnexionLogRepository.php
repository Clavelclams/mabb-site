<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\ConnexionLog;
use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConnexionLog>
 */
class ConnexionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConnexionLog::class);
    }

    /**
     * Compte les ÉCHECS de connexion pour une IP donnée sur les N dernières minutes.
     * Utilisé pour anti-brute-force.
     */
    public function countFailuresByIp(string $ip, int $minutes = 10): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$minutes} minutes");

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.ip = :ip')
            ->andWhere('l.succes = false')
            ->andWhere('l.createdAt > :t')
            ->setParameter('ip', $ip)
            ->setParameter('t', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les échecs sur un email donné — détection credential stuffing
     * sur un compte spécifique (qu'on peut alerter ensuite).
     */
    public function countFailuresByEmail(string $email, int $minutes = 60): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$minutes} minutes");

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.emailTente = :e')
            ->andWhere('l.succes = false')
            ->andWhere('l.createdAt > :t')
            ->setParameter('e', $email)
            ->setParameter('t', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pagination des logs pour la page admin /admin/logs-connexion.
     *
     * @return array{logs: ConnexionLog[], total: int}
     */
    public function paginate(int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.user', 'u')
            ->addSelect('u')
            ->orderBy('l.createdAt', 'DESC');

        if (!empty($filters['ip'])) {
            $qb->andWhere('l.ip = :ip')->setParameter('ip', $filters['ip']);
        }
        if (!empty($filters['email'])) {
            $qb->andWhere('l.emailTente LIKE :e')->setParameter('e', '%' . $filters['email'] . '%');
        }
        if (isset($filters['succes']) && $filters['succes'] !== null && $filters['succes'] !== '') {
            $qb->andWhere('l.succes = :s')->setParameter('s', (bool) $filters['succes']);
        }
        if (!empty($filters['contexte'])) {
            $qb->andWhere('l.contexte = :c')->setParameter('c', $filters['contexte']);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(l.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $logs = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['logs' => $logs, 'total' => $total];
    }

    /**
     * Purge les logs > N jours (RGPD : recommandation CNIL = 12 mois max).
     * À appeler depuis un cron quotidien.
     */
    public function purgeOlderThan(int $days = 365): int
    {
        $threshold = (new \DateTimeImmutable())->modify("-{$days} days");

        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :t')
            ->setParameter('t', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Récupère la dernière connexion réussie d'un user (pour affichage profil).
     */
    public function findLastSuccessForUser(User $user): ?ConnexionLog
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :u')
            ->andWhere('l.succes = true')
            ->setParameter('u', $user)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
