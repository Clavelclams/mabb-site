<?php

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Seance>
 */
class SeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seance::class);
    }

    /** Multi-tenant : ne retourne que les seance du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Prochaines séances d'une équipe (date >= maintenant).
     * Utilisé dans PIRB pour afficher le programme à venir.
     *
     * @return Seance[]
     */
    public function findProchaines(Equipe $equipe, int $limit = 5): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date >= :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances passées d'une équipe (date < maintenant).
     * Utilisé dans PIRB pour noter + voir l'historique.
     *
     * @return Seance[]
     */
    public function findPassees(Equipe $equipe, int $limit = 20): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date < :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Séances d'une équipe sur les 7 derniers jours — pour le widget "dernière séance".
     *
     * @return Seance[]
     */
    public function findRecentes(Equipe $equipe, int $joursArriere = 7): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.contenuSeance', 'c')
            ->addSelect('c')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date >= :debut')
            ->andWhere('s.date <= :now')
            ->setParameter('equipe', $equipe)
            ->setParameter('debut', new \DateTimeImmutable("-{$joursArriere} days"))
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les séances d'une semaine, pour la vue du coach.
     *
     * Ce sont les séances RÉELLES, pas les créneaux récurrents : celles qui ont une
     * date, qu'on peut ouvrir, et dont on peut faire l'appel. Le créneau
     * (PlanningSeance) dit "tous les mardis", la séance dit "mardi 14 juillet".
     *
     * @param User|null   $coach     si fourni, seulement les équipes de ce coach
     * @param string|null $categorie si fournie, seulement cette catégorie
     *
     * @return Seance[]
     */
    public function findSemaine(
        Club $club,
        \DateTimeImmutable $lundi,
        ?User $coach = null,
        ?string $categorie = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->join('s.equipe', 'e')->addSelect('e')
            ->leftJoin('s.presences', 'p')->addSelect('p')
            ->where('s.club = :club')
            ->andWhere('s.date >= :lundi')
            ->andWhere('s.date < :lundiSuivant')
            ->setParameter('club', $club)
            ->setParameter('lundi', $lundi->setTime(0, 0))
            ->setParameter('lundiSuivant', $lundi->modify('+7 days')->setTime(0, 0))
            ->orderBy('s.date', 'ASC');

        if ($coach !== null) {
            // On passe par la table de liaison : "les équipes que CE coach entraîne".
            $qb->join('e.coachs', 'c')
               ->andWhere('c = :coach')
               ->setParameter('coach', $coach);
        }

        if ($categorie !== null && $categorie !== '') {
            $qb->andWhere('e.categorie = :categorie')
               ->setParameter('categorie', $categorie);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les séances passées d'un coach dont l'appel n'a jamais été fait.
     *
     * "Appel fait" = au moins une ligne de présence existe pour la séance. Une séance
     * où tout le monde était absent produit quand même des lignes (présent = false),
     * donc elle ne remonte pas ici : c'est bien le comportement voulu.
     *
     * Sert au bandeau du dashboard. On remonte 30 jours : au-delà, relancer un
     * bénévole sur un oubli d'il y a deux mois ne sert plus à rien.
     *
     * @return Seance[]
     */
    public function findSansAppelPourCoach(User $coach, Club $club, int $joursArriere = 30): array
    {
        return $this->createQueryBuilder('s')
            ->join('s.equipe', 'e')->addSelect('e')
            ->join('e.coachs', 'c')
            ->leftJoin('s.presences', 'p')
            ->where('s.club = :club')
            ->andWhere('c = :coach')
            ->andWhere('s.date < :maintenant')
            ->andWhere('s.date >= :limite')
            ->groupBy('s.id')
            ->having('COUNT(p.id) = 0')
            ->setParameter('club', $club)
            ->setParameter('coach', $coach)
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->setParameter('limite', new \DateTimeImmutable("-{$joursArriere} days"))
            ->orderBy('s.date', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
