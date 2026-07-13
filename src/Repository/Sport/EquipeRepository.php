<?php

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\CoachEquipe;
use App\Entity\Sport\Equipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Equipe>
 */
class EquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Equipe::class);
    }

    /**
     * Combien d'équipes cet utilisateur coache-t-il dans ce club ?
     *
     * Sert à décider si on lui montre « mes créneaux » ou tout le club : un coach à
     * qui personne n'a encore assigné d'équipe verrait sinon une page vide, sans
     * comprendre que le problème vient de son paramétrage, pas du planning.
     *
     * On passe par CoachEquipe (l'entité qui porte l'affectation, avec sa saison et
     * son rôle), et non par une liaison directe sur Equipe : une telle liaison a
     * existé un temps, en doublon, elle a été supprimée.
     */
    public function countPourCoach(?UserInterface $user, Club $club, ?string $saison = null): int
    {
        if ($user === null) {
            return 0;
        }

        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->join(CoachEquipe::class, 'ce', 'WITH', 'ce.equipe = e AND ce.user = :user')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->setParameter('club', $club)
            ->setParameter('user', $user);

        if ($saison !== null) {
            $qb->andWhere('e.saison = :saison')->setParameter('saison', $saison);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Les saisons pour lesquelles ce club a au moins une équipe, la plus récente
     * d'abord. Alimente les sélecteurs de saison.
     *
     * @return string[]
     */
    public function saisonsDisponibles(Club $club): array
    {
        return $this->createQueryBuilder('e')
            ->select('DISTINCT e.saison')
            ->where('e.club = :club')
            ->andWhere('e.saison IS NOT NULL')
            ->setParameter('club', $club)
            ->orderBy('e.saison', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /** Multi-tenant : ne retourne que les equipe du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }
}
