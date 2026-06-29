<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\BilanCompetence;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BilanCompetence>
 */
class BilanCompetenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BilanCompetence::class);
    }

    /**
     * Tous les bilans d'une joueuse, triés du plus récent au plus ancien.
     *
     * @return BilanCompetence[]
     */
    public function findByJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('b.dateEvaluation', 'DESC')
            ->addOrderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Dernier bilan VALIDÉ d'une joueuse.
     * Utilisé côté PIRB pour afficher le bilan que la joueuse peut voir.
     */
    public function findDernierValide(Joueur $joueur): ?BilanCompetence
    {
        return $this->createQueryBuilder('b')
            ->where('b.joueur = :j')
            ->andWhere('b.statut = :statut')
            ->setParameter('j', $joueur)
            ->setParameter('statut', BilanCompetence::STATUT_VALIDE)
            ->orderBy('b.dateEvaluation', 'DESC')
            ->addOrderBy('b.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Tous les bilans d'un club pour une saison, avec joueur eager-loadé.
     * Utilisé sur la liste Manager pour éviter le N+1.
     *
     * @return BilanCompetence[]
     */
    public function findByClubAndSaison(Club $club, string $saison): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.joueur', 'j')->addSelect('j')
            ->leftJoin('b.coach', 'c')->addSelect('c')
            ->where('b.club = :club')
            ->andWhere('b.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->orderBy('j.nom', 'ASC')
            ->addOrderBy('b.dateEvaluation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Bilans créés par un coach donné, tous clubs confondus.
     * Utilisé sur le dashboard coach.
     *
     * @return BilanCompetence[]
     */
    public function findByCoach(User $coach, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->leftJoin('b.joueur', 'j')->addSelect('j')
            ->where('b.coach = :c')
            ->setParameter('c', $coach)
            ->orderBy('b.createdAt', 'DESC');

        if ($saison !== null) {
            $qb->andWhere('b.saison = :saison')->setParameter('saison', $saison);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne une map [joueur_id => BilanCompetence] avec le dernier bilan
     * (toutes saisons, tous statuts) pour chaque joueuse d'une liste.
     * Utilisé sur les cards joueuses du dashboard coach.
     *
     * @param Joueur[] $joueurs
     * @return array<int, BilanCompetence>  indexé par joueur_id
     */
    public function findLastBilanByJoueurs(array $joueurs): array
    {
        if (empty($joueurs)) return [];

        // Sous-requête : MAX(id) par joueur
        $rows = $this->createQueryBuilder('b')
            ->select('MAX(b.id) as last_id')
            ->where('b.joueur IN (:joueurs)')
            ->setParameter('joueurs', $joueurs)
            ->groupBy('b.joueur')
            ->getQuery()
            ->getScalarResult();

        $ids = array_column($rows, 'last_id');
        if (empty($ids)) return [];

        $bilans = $this->createQueryBuilder('b')
            ->where('b.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($bilans as $bilan) {
            /** @var BilanCompetence $bilan */
            $map[$bilan->getJoueur()?->getId()] = $bilan;
        }
        return $map;
    }
}
