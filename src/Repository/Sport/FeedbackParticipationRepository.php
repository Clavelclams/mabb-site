<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\FeedbackParticipation;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedbackParticipation>
 *
 * Ce repository ne renvoie JAMAIS de note ni de commentaire. Il ne sait pas ce
 * qu'une joueuse a écrit, seulement qu'elle a écrit. C'est volontaire.
 */
class FeedbackParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackParticipation::class);
    }

    /** Anti-doublon : cette joueuse a-t-elle déjà répondu pour cette séance ? */
    public function aDejaRepondu(Joueur $joueur, Seance $seance): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.joueur = :j')
            ->andWhere('p.seance = :s')
            ->setParameter('j', $joueur)
            ->setParameter('s', $seance)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Gamification : combien de retours cette joueuse a-t-elle donnés en tout ? */
    public function countByJoueur(Joueur $joueur): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.joueur = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pour l'écran joueuse : les séances déjà notées, en un seul appel.
     *
     * @return array<int, true> [seance_id => true]
     */
    public function seancesDejaNoteesParJoueur(Joueur $joueur): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.seance) AS seance_id')
            ->where('p.joueur = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['seance_id']] = true;
        }

        return $map;
    }
}
