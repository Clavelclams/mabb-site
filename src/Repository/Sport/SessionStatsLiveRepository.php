<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\SessionStatsLive;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SessionStatsLive>
 */
class SessionStatsLiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionStatsLive::class);
    }

    /**
     * Toutes les sessions d'une rencontre, OFFICIELLE en premier.
     *
     * @return SessionStatsLive[]
     */
    public function findByRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->addSelect("CASE
                WHEN s.statut = 'OFFICIELLE' THEN 0
                WHEN s.statut = 'EN_COURS' THEN 1
                WHEN s.statut = 'COMPLETE' THEN 2
                ELSE 3
            END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Session OFFICIELLE d'une rencontre (max 1 — garanti par la logique applicative).
     * Source de vérité pour les stats agrégées.
     */
    public function findOfficielleByRencontre(Rencontre $rencontre): ?SessionStatsLive
    {
        return $this->findOneBy([
            'rencontre' => $rencontre,
            'statut'    => SessionStatsLive::STATUT_OFFICIELLE,
        ]);
    }

    /**
     * Session EN_COURS du user pour cette rencontre (max 1 par user).
     * Permet la reprise : un bénévole qui rouvre Stats Live retrouve sa session.
     */
    public function findEnCoursForUserAndRencontre(User $user, Rencontre $rencontre): ?SessionStatsLive
    {
        return $this->createQueryBuilder('s')
            ->where('s.rencontre = :rencontre')
            ->andWhere('s.createdBy = :user')
            ->andWhere('s.statut = :enCours')
            ->setParameter('rencontre', $rencontre)
            ->setParameter('user', $user)
            ->setParameter('enCours', SessionStatsLive::STATUT_EN_COURS)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne une map rencontreId → SessionStatsLive pour toutes les rencontres
     * d'un club. Utilisé par la page "Stats Live" pour afficher le statut de
     * chaque rencontre sans faire N+1 requêtes.
     *
     * Priorité par rencontre : OFFICIELLE > EN_COURS > COMPLETE > ARCHIVEE.
     * Si plusieurs sessions existent pour une rencontre, on garde la plus prioritaire.
     *
     * @return array<int, SessionStatsLive>  clé = rencontre.id
     */
    public function findByClubIndexedByRencontre(int $clubId): array
    {
        /** @var SessionStatsLive[] $sessions */
        $sessions = $this->createQueryBuilder('s')
            ->join('s.rencontre', 'r')
            ->addSelect('r')
            ->where('r.club = :club')
            ->setParameter('club', $clubId)
            ->addSelect("CASE
                WHEN s.statut = 'OFFICIELLE' THEN 0
                WHEN s.statut = 'EN_COURS'   THEN 1
                WHEN s.statut = 'COMPLETE'   THEN 2
                ELSE 3
            END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Index par rencontre ID — première session rencontrée = la plus prioritaire
        $indexed = [];
        foreach ($sessions as $session) {
            $rid = $session->getRencontre()?->getId();
            if ($rid !== null && !isset($indexed[$rid])) {
                $indexed[$rid] = $session;
            }
        }
        return $indexed;
    }

    /**
     * Nombre de sessions officielles déjà attribuées à un user (gamification PIRB future).
     */
    public function countOfficiellesForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdBy = :user')
            ->andWhere('s.statut = :officielle')
            ->setParameter('user', $user)
            ->setParameter('officielle', SessionStatsLive::STATUT_OFFICIELLE)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
