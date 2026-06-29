<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AffectationMatch> */
class AffectationMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AffectationMatch::class);
    }

    /**
     * Toutes les affectations d'une rencontre (actives + candidatures).
     * Indexées par rôle pour usage facile en template.
     *
     * @return array<string, AffectationMatch[]>  role => [affectations]
     */
    public function findByRencontre(Rencontre $rencontre): array
    {
        $affectations = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->where('a.rencontre = :r')
            ->setParameter('r', $rencontre)
            ->orderBy('a.statut', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($affectations as $a) {
            $map[$a->getRole()][] = $a;
        }
        return $map;
    }

    /**
     * Affectation active (ASSIGNE ou CONFIRME) d'un rôle pour une rencontre.
     * Null si le rôle est vacant.
     */
    public function findActiveByRencontreAndRole(Rencontre $rencontre, string $role): ?AffectationMatch
    {
        return $this->createQueryBuilder('a')
            ->where('a.rencontre = :r')
            ->andWhere('a.role = :role')
            ->andWhere('a.statut IN (:statuts)')
            ->setParameter('r', $rencontre)
            ->setParameter('role', $role)
            ->setParameter('statuts', [AffectationMatch::STATUT_ASSIGNE, AffectationMatch::STATUT_CONFIRME])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Candidatures en attente pour une rencontre (statut CANDIDAT).
     *
     * @return AffectationMatch[]
     */
    public function findCandidatsByRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->where('a.rencontre = :r')
            ->andWhere('a.statut = :s')
            ->setParameter('r', $rencontre)
            ->setParameter('s', AffectationMatch::STATUT_CANDIDAT)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidature existante d'un user sur un rôle pour une rencontre.
     * Empêche le double-candidature.
     */
    public function findCandidatureByUserAndRencontreAndRole(
        User $user,
        Rencontre $rencontre,
        string $role
    ): ?AffectationMatch {
        return $this->createQueryBuilder('a')
            ->where('a.user = :u')
            ->andWhere('a.rencontre = :r')
            ->andWhere('a.role = :role')
            ->setParameter('u', $user)
            ->setParameter('r', $rencontre)
            ->setParameter('role', $role)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Missions à venir d'un user (actives + confirmées), triées par date de rencontre.
     *
     * @return AffectationMatch[]
     */
    public function findMissionsAVenir(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.rencontre', 'r')->addSelect('r')
            ->leftJoin('r.equipe', 'e')->addSelect('e')
            ->where('a.user = :u')
            ->andWhere('a.statut IN (:statuts)')
            ->andWhere('r.date >= :now')
            ->setParameter('u', $user)
            ->setParameter('statuts', [AffectationMatch::STATUT_ASSIGNE, AffectationMatch::STATUT_CONFIRME])
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('r.date', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidatures en attente d'un user (pour qu'il voie ses inscriptions en cours).
     *
     * @return AffectationMatch[]
     */
    public function findCandidaturesEnAttente(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.rencontre', 'r')->addSelect('r')
            ->where('a.user = :u')
            ->andWhere('a.statut = :s')
            ->andWhere('r.date >= :now')
            ->setParameter('u', $user)
            ->setParameter('s', AffectationMatch::STATUT_CANDIDAT)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres à venir avec au moins un rôle vacant (STATS_LIVE ou autre).
     * Utilisé pour proposer les rôles ouverts aux bénévoles.
     * "Vacant" = aucune affectation ASSIGNE/CONFIRME sur ce rôle.
     */
    public function findRencontresAvecRolesVacants(\DateTimeImmutable $from): array
    {
        // On retourne les rencontres, le filtrage des rôles se fait en PHP
        return $this->createQueryBuilder('a')
            ->select('DISTINCT r')
            ->leftJoin('a.rencontre', 'r')
            ->where('r.date >= :from')
            ->andWhere('a.statut NOT IN (:actifs)')
            ->setParameter('from', $from)
            ->setParameter('actifs', [AffectationMatch::STATUT_ASSIGNE, AffectationMatch::STATUT_CONFIRME])
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les absences signalées (ABSENT) pour les rencontres à venir.
     * Utilisé par le manager pour voir les problèmes.
     *
     * @return AffectationMatch[]
     */
    public function findAbsencesAVenir(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.rencontre', 'r')->addSelect('r')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->where('a.statut = :s')
            ->andWhere('r.date >= :now')
            ->setParameter('s', AffectationMatch::STATUT_ABSENT)
            ->setParameter('now', new \DateTimeImmutable('today'))
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
