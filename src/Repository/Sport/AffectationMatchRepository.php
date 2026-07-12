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
     * [OTM V2] Combien de fois cette personne tient-elle DÉJÀ ce poste, ce
     * jour-là ? Sert l'anti-répétition : sur 5 rencontres dans la journée, on
     * ne fait pas 3 fois le chrono (max 2, cf. OtmService).
     *
     * On ne compte que les affectations réellement couvertes (assigné/confirmé),
     * et on exclut la rencontre en cours de traitement pour ne pas se compter
     * soi-même lors d'une réaffectation.
     */
    public function compterMemePostePourJour(
        User $user,
        string $role,
        \DateTimeInterface $jour,
        ?Rencontre $exclure = null,
    ): int {
        $debut = \DateTimeImmutable::createFromInterface($jour)->setTime(0, 0);
        $fin   = $debut->setTime(23, 59, 59);

        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.rencontre', 'r')
            ->andWhere('a.user = :user')->setParameter('user', $user)
            ->andWhere('a.role = :role')->setParameter('role', $role)
            ->andWhere('a.statut IN (:actifs)')
            ->setParameter('actifs', [AffectationMatch::STATUT_ASSIGNE, AffectationMatch::STATUT_CONFIRME])
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin);

        if ($exclure !== null && $exclure->getId() !== null) {
            $qb->andWhere('r.id != :ex')->setParameter('ex', $exclure->getId());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
     *
     * [V2.4m FIX 500] Doctrine 3 refuse `select('DISTINCT r')` quand `r` est
     * un alias JOINT (« Cannot select entity through identification variables
     * without choosing at least one root entity alias »). Bug latent : la
     * méthode n'était appelée nulle part avant la card « Le club cherche du
     * monde » du dashboard. Requête réécrite avec Rencontre en RACINE.
     *
     * @return \App\Entity\Sport\Rencontre[]
     */
    public function findRencontresAvecRolesVacants(\DateTimeImmutable $from): array
    {
        // On retourne les rencontres, le filtrage des rôles se fait en PHP
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT r')
            ->from(\App\Entity\Sport\Rencontre::class, 'r')
            ->join(AffectationMatch::class, 'a', 'WITH', 'a.rencontre = r')
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
