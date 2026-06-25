<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\ThemeSeance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ThemeSeance> */
class ThemeSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThemeSeance::class);
    }

    /**
     * Tous les thèmes disponibles pour un club, groupés par groupe.
     * Inclut les thèmes système (isSysteme=true) + les thèmes custom du club.
     *
     * @return array<string, ThemeSeance[]>  clé = groupe
     */
    public function findParGroupePourClub(Club $club): array
    {
        $themes = $this->createQueryBuilder('t')
            ->where('t.isSysteme = true OR t.club = :club')
            ->setParameter('club', $club)
            ->orderBy('t.groupe', 'ASC')
            ->addOrderBy('t.libelle', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($themes as $theme) {
            $grouped[$theme->getGroupe()][] = $theme;
        }
        return $grouped;
    }

    /**
     * Tous les thèmes à plat pour un club (pour EntityType formulaire).
     *
     * @return ThemeSeance[]
     */
    public function findTousPourClub(Club $club): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isSysteme = true OR t.club = :club')
            ->setParameter('club', $club)
            ->orderBy('t.groupe', 'ASC')
            ->addOrderBy('t.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Trouver un thème système par son slug */
    public function findBySlug(string $slug): ?ThemeSeance
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
