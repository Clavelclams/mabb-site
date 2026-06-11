<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\CoachEquipe;
use App\Entity\Sport\Equipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CoachEquipe> */
class CoachEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoachEquipe::class);
    }

    /** @return CoachEquipe[] */
    public function findByEquipe(Equipe $equipe, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('ce')
            ->leftJoin('ce.user', 'u')->addSelect('u')
            ->where('ce.equipe = :e')
            ->setParameter('e', $equipe)
            ->orderBy('ce.roleCoach', 'ASC'); // PRINCIPAL avant ASSISTANT (alpha)

        if ($saison !== null) {
            $qb->andWhere('ce.saison = :s OR ce.saison IS NULL')->setParameter('s', $saison);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return CoachEquipe[] */
    public function findByCoach(User $user, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('ce')
            ->leftJoin('ce.equipe', 'e')->addSelect('e')
            ->where('ce.user = :u')
            ->setParameter('u', $user)
            ->orderBy('e.nom', 'ASC');

        if ($saison !== null) {
            $qb->andWhere('ce.saison = :s OR ce.saison IS NULL')->setParameter('s', $saison);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Le user $coach est-il coach (principal ou assistant) de l'équipe du $joueur ?
     * Utilisé par B13 visibilité 5 paliers (palier "mon coach").
     */
    public function estCoachDeEquipe(User $coach, Equipe $equipe): bool
    {
        $count = $this->createQueryBuilder('ce')
            ->select('COUNT(ce.id)')
            ->where('ce.user = :u')
            ->andWhere('ce.equipe = :e')
            ->setParameter('u', $coach)
            ->setParameter('e', $equipe)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
