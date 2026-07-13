<?php

namespace App\Repository\Sport;

use App\Entity\Core\Club;
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
     */
    public function countPourCoach(?UserInterface $user, Club $club): int
    {
        if ($user === null) {
            return 0;
        }

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.coachs', 'c')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->andWhere('c = :user')
            ->setParameter('club', $club)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
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
