<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\Club;
use App\Entity\Core\Notification;
use App\Entity\Core\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Retourne les 50 dernières notifications d'un user pour un club,
     * triées par date décroissante (les plus récentes en premier).
     *
     * @return Notification[]
     */
    public function findByUserAndClub(User $user, Club $club): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.destinataire = :user')
            ->andWhere('n.club = :club')
            ->setParameter('user', $user)
            ->setParameter('club', $club)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les notifications non lues d'un user pour un club.
     *
     * Cette méthode est appelée sur CHAQUE page PIRB (via Twig extension).
     * Elle est optimisée : requête COUNT sur index composite (destinataire_id, club_id, lue).
     */
    public function countUnreadByUserAndClub(User $user, Club $club): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.destinataire = :user')
            ->andWhere('n.club = :club')
            ->andWhere('n.lue = false')
            ->setParameter('user', $user)
            ->setParameter('club', $club)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marque toutes les notifications non lues d'un user comme lues (bulk UPDATE).
     * Appelé quand l'user ouvre la page /notifications.
     *
     * Pourquoi un UPDATE en masse plutôt qu'un loop ?
     *   1 requête SQL vs potentiellement N — beaucoup plus efficace.
     */
    public function markAllReadByUserAndClub(User $user, Club $club): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.lue', ':true')
            ->where('n.destinataire = :user')
            ->andWhere('n.club = :club')
            ->andWhere('n.lue = false')
            ->setParameter('true', true)
            ->setParameter('user', $user)
            ->setParameter('club', $club)
            ->getQuery()
            ->execute();
    }
}
