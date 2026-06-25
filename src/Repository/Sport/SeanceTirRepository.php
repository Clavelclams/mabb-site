<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\SeanceTir;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeanceTir>
 */
class SeanceTirRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeanceTir::class);
    }

    /**
     * Toutes les séances d'une joueuse, ordonnées par date desc.
     *
     * @return SeanceTir[]
     */
    public function findByJoueur(Joueur $joueur, bool $validatedOnly = false): array
    {
        $qb = $this->createQueryBuilder('st')
            ->addSelect('z')
            ->leftJoin('st.zones', 'z')
            ->where('st.joueur = :joueur')
            ->setParameter('joueur', $joueur)
            ->orderBy('st.dateSeance', 'DESC')
            ->addOrderBy('st.createdAt', 'DESC');

        if ($validatedOnly) {
            $qb->andWhere('st.validatedByCoach = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Séances d'une joueuse sur un club (multi-tenant safe).
     *
     * @return SeanceTir[]
     */
    public function findByJoueurAndClub(Joueur $joueur, Club $club, bool $validatedOnly = false): array
    {
        $qb = $this->createQueryBuilder('st')
            ->addSelect('z')
            ->leftJoin('st.zones', 'z')
            ->where('st.joueur = :joueur')
            ->andWhere('st.club = :club')
            ->setParameter('joueur', $joueur)
            ->setParameter('club', $club)
            ->orderBy('st.dateSeance', 'DESC');

        if ($validatedOnly) {
            $qb->andWhere('st.validatedByCoach = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Séances en attente de validation coach pour un club.
     *
     * @return SeanceTir[]
     */
    public function findPendingValidation(Club $club): array
    {
        return $this->createQueryBuilder('st')
            ->addSelect('j', 'z')
            ->join('st.joueur', 'j')
            ->leftJoin('st.zones', 'z')
            ->where('st.club = :club')
            ->andWhere('st.validatedByCoach = false')
            ->andWhere('st.source = :source')
            ->setParameter('club', $club)
            ->setParameter('source', SeanceTir::SOURCE_ENTRAINEMENT)
            ->orderBy('st.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances d'une joueuse pour la shot map (filtres optionnels).
     *
     * @return SeanceTir[]
     */
    public function findForShotMap(
        Joueur $joueur,
        ?string $source = null,       // 'MATCH' | 'ENTRAINEMENT' | null (tous)
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to   = null,
        bool $validatedOnly = true
    ): array {
        $qb = $this->createQueryBuilder('st')
            ->addSelect('z')
            ->leftJoin('st.zones', 'z')
            ->where('st.joueur = :joueur')
            ->setParameter('joueur', $joueur)
            ->orderBy('st.dateSeance', 'DESC');

        if ($validatedOnly) {
            $qb->andWhere('st.validatedByCoach = true');
        }

        if ($source !== null) {
            $qb->andWhere('st.source = :source')->setParameter('source', $source);
        }

        if ($from !== null) {
            $qb->andWhere('st.dateSeance >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('st.dateSeance <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Stats globales par type de tir pour une joueuse.
     * Utilisé pour les courbes de progression.
     *
     * Retourne :
     * [
     *   ['date' => '2026-01-15', 'typeTir' => '3pt', 'tentatives' => 16, 'reussis' => 9],
     *   ...
     * ]
     *
     * @return array<int, array{date: string, typeTir: string, tentatives: int, reussis: int}>
     */
    public function findProgressionData(
        Joueur $joueur,
        ?string $typeTir = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to   = null
    ): array {
        $qb = $this->createQueryBuilder('st')
            ->select(
                'st.dateSeance as date',
                'z.typeTir as typeTir',
                'SUM(z.tentatives) as tentatives',
                'SUM(z.reussis)    as reussis'
            )
            ->join('st.zones', 'z')
            ->where('st.joueur = :joueur')
            ->andWhere('st.validatedByCoach = true')
            ->setParameter('joueur', $joueur)
            ->groupBy('st.dateSeance', 'z.typeTir')
            ->orderBy('st.dateSeance', 'ASC');

        if ($typeTir !== null) {
            $qb->andWhere('z.typeTir = :typeTir')->setParameter('typeTir', $typeTir);
        }

        if ($from !== null) {
            $qb->andWhere('st.dateSeance >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('st.dateSeance <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getArrayResult();
    }
}
