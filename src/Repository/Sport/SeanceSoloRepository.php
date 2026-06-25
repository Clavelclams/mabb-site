<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\SeanceSolo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SeanceSolo> */
class SeanceSoloRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeanceSolo::class);
    }

    /**
     * Séances solo d'une joueuse, triées par date décroissante.
     *
     * @return SeanceSolo[]
     */
    public function findParJoueur(Joueur $joueur, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('s.dateSolo', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances solo en attente pour les équipes d'un coach.
     * Utilisé dans Manager pour la validation en masse.
     *
     * @param  Equipe[]  $equipes
     * @return SeanceSolo[]
     */
    public function findPendingParEquipes(array $equipes): array
    {
        if (empty($equipes)) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.joueur', 'j')
            ->addSelect('j')
            ->where('s.statut = :pending')
            ->andWhere('j.equipe IN (:equipes)')
            ->setParameter('pending', SeanceSolo::STATUT_PENDING)
            ->setParameter('equipes', $equipes)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les séances solo pour les équipes d'un coach (historique).
     *
     * @param  Equipe[]  $equipes
     * @return SeanceSolo[]
     */
    public function findToutesParEquipes(array $equipes, int $limit = 50): array
    {
        if (empty($equipes)) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->join('s.joueur', 'j')
            ->addSelect('j')
            ->where('j.equipe IN (:equipes)')
            ->setParameter('equipes', $equipes)
            ->orderBy('s.dateSolo', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Nombre de séances solo pending pour un set d'équipes (badge notification). */
    public function countPendingParEquipes(array $equipes): int
    {
        if (empty($equipes)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->join('s.joueur', 'j')
            ->where('s.statut = :pending')
            ->andWhere('j.equipe IN (:equipes)')
            ->setParameter('pending', SeanceSolo::STATUT_PENDING)
            ->setParameter('equipes', $equipes)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
