<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\PresenceTerrain;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PresenceTerrain>
 */
class PresenceTerrainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PresenceTerrain::class);
    }

    /**
     * Toutes les présences d'une rencontre (joueuses + entrées/sorties).
     *
     * @return PresenceTerrain[]
     */
    public function findByRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->orderBy('p.secondesEntree', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Joueuses ACTUELLEMENT sur le terrain (secondesSortie IS NULL).
     *
     * @return PresenceTerrain[]
     */
    public function findEnCoursByRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.rencontre = :rencontre')
            ->andWhere('p.secondesSortie IS NULL')
            ->setParameter('rencontre', $rencontre)
            ->getQuery()
            ->getResult();
    }

    /**
     * Présence en cours d'UNE joueuse (la plus récente où elle n'est pas sortie).
     * Null si elle n'est pas sur le terrain.
     */
    public function findEnCoursForJoueur(Joueur $joueur, Rencontre $rencontre): ?PresenceTerrain
    {
        return $this->createQueryBuilder('p')
            ->where('p.rencontre = :rencontre')
            ->andWhere('p.joueur = :joueur')
            ->andWhere('p.secondesSortie IS NULL')
            ->setParameter('rencontre', $rencontre)
            ->setParameter('joueur', $joueur)
            ->orderBy('p.secondesEntree', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Temps de jeu TOTAL d'une joueuse pour une rencontre, en secondes.
     * Pour les présences en cours, utilise $tempsActuel (chrono live).
     */
    public function getTempsJeuTotalSecondes(Joueur $joueur, Rencontre $rencontre, int $tempsActuel = 0): int
    {
        $presences = $this->createQueryBuilder('p')
            ->where('p.rencontre = :rencontre')
            ->andWhere('p.joueur = :joueur')
            ->setParameter('rencontre', $rencontre)
            ->setParameter('joueur', $joueur)
            ->getQuery()
            ->getResult();

        $total = 0;
        foreach ($presences as $p) {
            /** @var PresenceTerrain $p */
            if ($p->getSecondesSortie() !== null) {
                $total += $p->getSecondesSortie() - $p->getSecondesEntree();
            } else {
                // Présence en cours → utiliser le chrono actuel
                $total += max(0, $tempsActuel - $p->getSecondesEntree());
            }
        }
        return $total;
    }

    /**
     * Map joueurId → temps de jeu en secondes (pour tous les joueurs ayant
     * eu au moins UNE présence sur la rencontre).
     *
     * @return array<int, int>
     */
    public function getMapTempsJeuByRencontre(Rencontre $rencontre, int $tempsActuel = 0): array
    {
        $presences = $this->findByRencontre($rencontre);
        $map = [];

        foreach ($presences as $p) {
            $jid = $p->getJoueur()?->getId();
            if ($jid === null) continue;
            if (!isset($map[$jid])) {
                $map[$jid] = 0;
            }
            if ($p->getSecondesSortie() !== null) {
                $map[$jid] += $p->getSecondesSortie() - $p->getSecondesEntree();
            } else {
                $map[$jid] += max(0, $tempsActuel - $p->getSecondesEntree());
            }
        }
        return $map;
    }
}
