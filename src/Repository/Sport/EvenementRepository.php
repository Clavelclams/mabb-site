<?php

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Evenement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Evenement>
 */
class EvenementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evenement::class);
    }

    /**
     * Événements à venir d'un club (date >= now), triés par date asc.
     *
     * @param array $statuts statuts à inclure (par défaut juste PUBLIE)
     * @return Evenement[]
     */
    public function avenirParClub(Club $club, array $statuts = [Evenement::STATUT_PUBLIE], int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :c')->setParameter('c', $club)
            ->andWhere('e.date >= :now')->setParameter('now', new \DateTimeImmutable())
            ->andWhere('e.statut IN (:statuts)')->setParameter('statuts', $statuts)
            ->orderBy('e.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Événements passés du club, triés par date desc (historique récent).
     *
     * @return Evenement[]
     */
    public function passesParClub(Club $club, array $statuts = [Evenement::STATUT_PUBLIE], int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :c')->setParameter('c', $club)
            ->andWhere('e.date < :now')->setParameter('now', new \DateTimeImmutable())
            ->andWhere('e.statut IN (:statuts)')->setParameter('statuts', $statuts)
            ->orderBy('e.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Tous les événements (toutes dates, tous statuts) pour un club — usage staff.
     *
     * @return Evenement[]
     */
    public function tousParClub(Club $club): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :c')->setParameter('c', $club)
            ->orderBy('e.date', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Sorties (type SORTIE) d'un club dans une fenêtre de dates (une saison),
     * triées par date décroissante. Tous statuts (le staff voit tout).
     *
     * @return Evenement[]
     */
    public function sortiesParClubEtSaison(Club $club, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :c')->setParameter('c', $club)
            ->andWhere('e.type = :t')->setParameter('t', Evenement::TYPE_SORTIE)
            ->andWhere('e.date >= :debut')->setParameter('debut', $debut)
            ->andWhere('e.date < :fin')->setParameter('fin', $fin)
            ->orderBy('e.date', 'DESC')
            ->getQuery()->getResult();
    }
}
