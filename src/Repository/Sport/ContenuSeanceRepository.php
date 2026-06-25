<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\ContenuSeance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContenuSeance> */
class ContenuSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContenuSeance::class);
    }

    /**
     * Bibliothèque de séances visible par un user dans un club.
     * Inclut : les fiches publiques du club + les fiches privées du user.
     *
     * @return ContenuSeance[]
     */
    public function findBibliotheque(Club $club, User $user): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.themes', 't')
            ->addSelect('t')
            ->where('c.club = :club')
            ->andWhere('c.isPublicClub = true OR c.createdBy = :user')
            ->setParameter('club', $club)
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Filtrer la bibliothèque par catégorie d'âge.
     * JSON_CONTAINS n'est pas disponible en DQL standard → utilise LIKE sur JSON.
     * Fonctionne car les catégories sont des strings simples sans caractères spéciaux.
     *
     * @return ContenuSeance[]
     */
    public function findBibliothequeFiltree(Club $club, User $user, ?string $categorieAge = null, ?int $themeId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.themes', 't')
            ->addSelect('t')
            ->where('c.club = :club')
            ->andWhere('c.isPublicClub = true OR c.createdBy = :user')
            ->setParameter('club', $club)
            ->setParameter('user', $user);

        if ($categorieAge !== null) {
            // Filtre JSON : la catégorie est dans le tableau categoriesAge
            $qb->andWhere('JSON_CONTAINS(c.categoriesAge, :cat) = 1 OR c.categoriesAge = \'[]\' OR c.categoriesAge IS NULL')
               ->setParameter('cat', json_encode($categorieAge));
        }

        if ($themeId !== null) {
            $qb->andWhere(':themeId MEMBER OF c.themes')
               ->setParameter('themeId', $themeId);
        }

        return $qb->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
