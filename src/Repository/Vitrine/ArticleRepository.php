<?php

namespace App\Repository\Vitrine;

use App\Entity\Vitrine\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /** 3 derniers articles publiés — pour l'accueil */
    public function findDerniersPublies(int $limit = 3): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.statut = :statut')
            ->setParameter('statut', Article::STATUT_PUBLIE)
            ->orderBy('a.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Tous les articles publiés — pour /news paginé */
    public function findPubliesPagines(int $page = 1, int $perPage = 9): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.statut = :statut')
            ->setParameter('statut', Article::STATUT_PUBLIE)
            ->orderBy('a.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countPublies(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.statut = :statut')
            ->setParameter('statut', Article::STATUT_PUBLIE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
