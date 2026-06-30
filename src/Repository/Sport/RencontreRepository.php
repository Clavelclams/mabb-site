<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rencontre>
 */
class RencontreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rencontre::class);
    }

    /** Multi-tenant : ne retourne que les rencontres du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres du club triées par date décroissante (plus récente en premier),
     * avec JOIN equipe pour éviter les N+1 requêtes dans la page Stats Live.
     *
     * @return Rencontre[]
     */
    public function findByClubOrderedDesc(int $clubId): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les rencontres du club qui ont au moins un PDF FFBB uploadé.
     * Utilisé dans l'ENT pour la section "PDFs officiels FFBB".
     * Triées par date décroissante (match le plus récent en premier).
     *
     * @return Rencontre[]
     */
    public function findWithPdfsByClub(int $clubId): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            ->andWhere(
                'r.resumePath IS NOT NULL OR r.feuilleMatchPath IS NOT NULL OR r.positionsTirsPath IS NOT NULL'
            )
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres d'une saison ayant un PDF positiontir_*.pdf uploadé.
     * Utilisé par ProcessPositionsTirsCommand pour le traitement en lot.
     *
     * @return Rencontre[]
     */
    public function findBySaisonWithPositionsPdf(string $saison): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.saison = :saison')
            ->andWhere('r.positionsTirsPath IS NOT NULL')
            ->setParameter('saison', $saison)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
