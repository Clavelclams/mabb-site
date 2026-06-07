<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionConvocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReunionConvocation>
 */
class ReunionConvocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReunionConvocation::class);
    }

    /**
     * Réunions à venir où l'user est convoqué (status planifie + date >= now).
     * Utilisé pour le bandeau "Mes prochaines réunions" sur dashboard Manager.
     *
     * @return ReunionConvocation[]
     */
    public function findMesReunionsAVenir(User $user, ?Club $club = null): array
    {
        $qb = $this->createQueryBuilder('rc')
            ->join('rc.reunion', 'r')
            ->where('rc.user = :user')
            ->andWhere('r.date >= :now')
            ->andWhere('r.statut = :planifie')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('planifie', Reunion::STATUT_PLANIFIE)
            ->orderBy('r.date', 'ASC');

        if ($club !== null) {
            $qb->andWhere('r.club = :club')->setParameter('club', $club);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * PV non lus par l'user (réunions tenues + PV saisi + pvLuAt null).
     * Utilisé pour le badge "Nouveaux PV à lire" sur dashboard.
     *
     * @return ReunionConvocation[]
     */
    public function findPvNonLus(User $user, ?Club $club = null): array
    {
        $qb = $this->createQueryBuilder('rc')
            ->join('rc.reunion', 'r')
            ->where('rc.user = :user')
            ->andWhere('rc.pvLuAt IS NULL')
            ->andWhere('r.statut = :tenue')
            ->andWhere('r.pvContenu IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('tenue', Reunion::STATUT_TENUE)
            ->orderBy('r.date', 'DESC');

        if ($club !== null) {
            $qb->andWhere('r.club = :club')->setParameter('club', $club);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve la convocation d'un user sur une réunion (unicité BDD).
     */
    public function findOneByReunionAndUser(Reunion $reunion, User $user): ?ReunionConvocation
    {
        return $this->createQueryBuilder('rc')
            ->where('rc.reunion = :reunion')
            ->andWhere('rc.user = :user')
            ->setParameter('reunion', $reunion)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
