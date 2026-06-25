<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
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

    /**
     * Tous les liens (actifs, pending, refusés) d'une joueuse donnée.
     * Utilisé sur la fiche joueuse Manager pour afficher les parents liés.
     *
     * @return ParentJoueur[]
     */
    public function findByJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('pj')
            ->join('pj.parentUser', 'u')
            ->addSelect('u')
            ->where('pj.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('pj.statut', 'ASC') // active avant pending avant rejected
            ->addOrderBy('pj.createdAt', 'DESC')
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
