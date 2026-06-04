<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Mission;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mission>
 */
class MissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mission::class);
    }

    /**
     * Compte les missions d'un joueur, optionnellement filtrées par type
     * et/ou par fenêtre temporelle (saison sportive).
     */
    public function countPourJoueur(
        Joueur $joueur,
        ?string $type = null,
        ?\DateTimeImmutable $depuis = null,
        ?\DateTimeImmutable $jusquA = null,
    ): int {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.joueur = :j')->setParameter('j', $joueur);

        if ($type !== null) {
            $qb->andWhere('m.type = :t')->setParameter('t', $type);
        }
        if ($depuis !== null) {
            $qb->andWhere('m.date >= :d')->setParameter('d', $depuis);
        }
        if ($jusquA !== null) {
            $qb->andWhere('m.date <= :j2')->setParameter('j2', $jusquA);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Liste les missions d'un joueur entre deux dates, triées date desc.
     *
     * @return Mission[]
     */
    public function pourJoueurDansPeriode(
        Joueur $joueur,
        ?\DateTimeImmutable $depuis = null,
        ?\DateTimeImmutable $jusquA = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.joueur = :j')->setParameter('j', $joueur)
            ->orderBy('m.date', 'DESC');

        if ($depuis !== null) {
            $qb->andWhere('m.date >= :d')->setParameter('d', $depuis);
        }
        if ($jusquA !== null) {
            $qb->andWhere('m.date <= :j2')->setParameter('j2', $jusquA);
        }

        return $qb->getQuery()->getResult();
    }
}
