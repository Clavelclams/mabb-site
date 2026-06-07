<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Reunion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reunion>
 */
class ReunionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reunion::class);
    }

    /**
     * Toutes les réunions d'un club, ordre chronologique inverse (récentes d'abord).
     * @return Reunion[]
     */
    public function findByClub(Club $club): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.club = :club')
            ->setParameter('club', $club)
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Réunions à venir d'un club (date >= now et statut planifie).
     * @return Reunion[]
     */
    public function findAVenir(Club $club): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.club = :club')
            ->andWhere('r.date >= :now')
            ->andWhere('r.statut = :statut')
            ->setParameter('club', $club)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('statut', Reunion::STATUT_PLANIFIE)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dernière réunion TENUE d'un club dont la synthèse publique est visible
     * pour l'un des rôles donnés.
     *
     * IMPLÉMENTATION : pré-filtre SQL (club + statut + synthèse présente),
     * puis filtre fin en PHP via Reunion::syntheseVisibleA().
     *
     * Pourquoi pas du JSON_CONTAINS pur SQL ?
     *   - JSON_CONTAINS n'est pas DQL natif Doctrine → faudrait déclarer la
     *     fonction dans doctrine.yaml. Couplage en plus, gain perf marginal.
     *   - La méthode syntheseVisibleA() existe déjà sur l'entité → on s'appuie
     *     dessus (DRY). Si la règle de visibilité change un jour, on la modifie
     *     à UN endroit (l'entité), pas en 2 (l'entité + le SQL).
     *
     * Perf : on hydrate au max 20 entités pour en garder 1. Acceptable pour
     * un dashboard. Si un jour on a 10k réunions tenues, on optimisera.
     *
     * @param string[] $rolesUser Rôles de l'utilisateur (codes UserClubRole::ROLE_*)
     */
    public function findDerniereTenueVisibleA(Club $club, array $rolesUser): ?Reunion
    {
        if ($rolesUser === []) {
            return null;
        }

        $candidates = $this->createQueryBuilder('r')
            ->where('r.club = :club')
            ->andWhere('r.statut = :statut')
            ->andWhere('r.synthesePublique IS NOT NULL')
            ->andWhere('r.syntheseVisibleRoles IS NOT NULL')
            ->setParameter('club', $club)
            ->setParameter('statut', Reunion::STATUT_TENUE)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $reunion) {
            /** @var Reunion $reunion */
            if ($reunion->syntheseVisibleA($rolesUser)) {
                return $reunion;
            }
        }

        return null;
    }
}
