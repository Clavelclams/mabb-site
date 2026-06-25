<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seance>
 */
class SeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seance::class);
    }

    /** Multi-tenant : ne retourne que les seance du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Prochaines séances d'une équipe (date >= maintenant).
     * Utilisé dans PIRB pour afficher le programme à venir.
     *
     * @return Seance[]
     */
    public function findProchaines(Equipe $equipe, int $limit = 5): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date >= :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances passées d'une équipe (date < maintenant).
     * Utilisé dans PIRB pour noter + voir l'historique.
     *
     * @return Seance[]
     */
    public function findPassees(Equipe $equipe, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date < :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances d'une équipe sur les 7 derniers jours — pour le widget "dernière séance".
     *
     * @return Seance[]
     */
    public function findRecentes(Equipe $equipe, int $joursArriere = 7): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date >= :debut')
            ->andWhere('s.date <= :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('debut', new \DateTimeImmutable("-{$joursArriere} days"))
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
