<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\ParentJoueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ParentJoueur>
 */
class ParentJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParentJoueur::class);
    }

    /** Tous les liens ACTIVE d'un parent (= ses enfants validés). */
    public function findEnfantsActifs(User $parent): array
    {
        return $this->createQueryBuilder('pj')
            ->where('pj.parentUser = :p')
            ->andWhere('pj.statut = :a')
            ->setParameter('p', $parent)
            ->setParameter('a', ParentJoueur::STATUT_ACTIVE)
            ->getQuery()
            ->getResult();
    }

    /** Demandes en attente côté Manager pour un club. */
    public function findDemandesEnAttenteParClub(Club $club): array
    {
        return $this->createQueryBuilder('pj')
            ->join('pj.joueur', 'j')
            ->where('j.club = :c')
            ->andWhere('pj.statut = :s')
            ->setParameter('c', $club)
            ->setParameter('s', ParentJoueur::STATUT_PENDING)
            ->orderBy('pj.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
