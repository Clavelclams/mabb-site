<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\NoteFrais;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteFrais>
 *
 * Repository des notes de frais. Toutes les méthodes filtrent par CLUB
 * (multi-tenant strict) sauf findByDemandeur qui peut filtrer en plus.
 */
class NoteFraisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteFrais::class);
    }

    /**
     * Notes déposées par un demandeur dans un club.
     * Triées : EN_ATTENTE d'abord (pour qu'il voie sa file active),
     * puis les autres par date desc.
     *
     * @return NoteFrais[]
     */
    public function findByDemandeur(User $demandeur, Club $club): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.club = :club')
            ->andWhere('n.demandeur = :demandeur')
            ->setParameter('club', $club)
            ->setParameter('demandeur', $demandeur)
            // Statut EN_ATTENTE en premier via CASE WHEN
            ->addSelect("CASE WHEN n.statut = 'EN_ATTENTE' THEN 0 ELSE 1 END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Notes en attente de validation pour un club.
     * Utilisé par la vue trésorier (file d'attente à traiter).
     *
     * @return NoteFrais[]
     */
    public function findEnAttenteByClub(Club $club): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.club = :club')
            ->andWhere('n.statut = :statut')
            ->setParameter('club', $club)
            ->setParameter('statut', NoteFrais::STATUT_EN_ATTENTE)
            ->orderBy('n.createdAt', 'ASC') // FIFO : premier arrivé, premier traité
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique des notes traitées (validées + rejetées) pour un club.
     *
     * @return NoteFrais[]
     */
    public function findTraiteesByClub(Club $club, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.club = :club')
            ->andWhere('n.statut IN (:statuts)')
            ->setParameter('club', $club)
            ->setParameter('statuts', [NoteFrais::STATUT_VALIDEE, NoteFrais::STATUT_REJETEE])
            ->orderBy('n.dateValidation', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compteur rapide pour badge "X notes à valider" sur le dashboard trésorier.
     */
    public function countEnAttente(Club $club): int
    {
        $result = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.club = :club')
            ->andWhere('n.statut = :statut')
            ->setParameter('club', $club)
            ->setParameter('statut', NoteFrais::STATUT_EN_ATTENTE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
