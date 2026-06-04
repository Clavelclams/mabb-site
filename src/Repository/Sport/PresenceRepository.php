<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\Presence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Presence>
 */
class PresenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presence::class);
    }

    /**
     * Multi-tenant : presence du club (via le joueur, qui porte le club).
     * Note : Presence n'a pas de champ club direct — la jointure passe par joueur.
     */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.joueur', 'j')
            ->andWhere('j.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les présences d'un joueur, triées par date d'événement croissante.
     * Récupère séances ET rencontres. Le tri par date côté PHP car DQL avec
     * coalesce sur deux relations est lourd à maintenir — quelques dizaines
     * de rows par joueur, performances ok.
     *
     * @return Presence[]
     */
    public function pourJoueur(Joueur $joueur): array
    {
        $rows = $this->createQueryBuilder('p')
            ->leftJoin('p.seance', 's')->addSelect('s')
            ->leftJoin('p.rencontre', 'r')->addSelect('r')
            ->andWhere('p.joueur = :j')->setParameter('j', $joueur)
            ->getQuery()
            ->getResult();

        // Tri chronologique : date de la séance ou de la rencontre
        usort($rows, function (Presence $a, Presence $b) {
            $dateA = $a->getSeance()?->getDate() ?? $a->getRencontre()?->getDate();
            $dateB = $b->getSeance()?->getDate() ?? $b->getRencontre()?->getDate();
            if ($dateA === null || $dateB === null) return 0;
            return $dateA <=> $dateB;
        });

        return $rows;
    }
}
