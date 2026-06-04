<?php

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Evenement;
use App\Entity\Sport\EvenementParticipation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvenementParticipation>
 */
class EvenementParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvenementParticipation::class);
    }

    /**
     * Vérifie si un user est déjà inscrit à un événement.
     */
    public function trouverPour(User $user, Evenement $evenement): ?EvenementParticipation
    {
        return $this->findOneBy(['user' => $user, 'evenement' => $evenement]);
    }
}
