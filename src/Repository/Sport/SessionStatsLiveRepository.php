<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\SessionStatsLive;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionStatsLive>
 */
class SessionStatsLiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionStatsLive::class);
    }

    /**
     * Toutes les sessions d'une rencontre, OFFICIELLE en premier.
     *
     * @return SessionStatsLive[]
     */
    public function findByRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->addSelect("CASE
                WHEN s.statut = 'OFFICIELLE' THEN 0
                WHEN s.statut = 'EN_COURS' THEN 1
                WHEN s.statut = 'COMPLETE' THEN 2
                ELSE 3
            END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Session OFFICIELLE d'une rencontre (max 1 — garanti par la logique applicative).
     * Source de vérité pour les stats agrégées.
     */
    public function findOfficielleByRencontre(Rencontre $rencontre): ?SessionStatsLive
    {
        return $this->findOneBy([
            'rencontre' => $rencontre,
            'statut'    => SessionStatsLive::STATUT_OFFICIELLE,
        ]);
    }

    /**
     * Session EN_COURS du user pour cette rencontre (max 1 par user).
     * Permet la reprise : un bénévole qui rouvre Stats Live retrouve sa session.
     */
    public function findEnCoursForUserAndRencontre(User $user, Rencontre $rencontre): ?SessionStatsLive
    {
        return $this->createQueryBuilder('s')
            ->where('s.rencontre = :rencontre')
            ->andWhere('s.createdBy = :user')
            ->andWhere('s.statut = :enCours')
            ->setParameter('rencontre', $rencontre)
            ->setParameter('user', $user)
            ->setParameter('enCours', SessionStatsLive::STATUT_EN_COURS)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Nombre de sessions officielles déjà attribuées à un user (gamification PIRB future).
     */
    public function countOfficiellesForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdBy = :user')
            ->andWhere('s.statut = :officielle')
            ->setParameter('user', $user)
            ->setParameter('officielle', SessionStatsLive::STATUT_OFFICIELLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
