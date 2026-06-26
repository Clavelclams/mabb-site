<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Tous les documents d'un club, triés par date décroissante.
     *
     * @return Document[]
     */
    public function findByClub(Club $club): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.club = :club')
            ->setParameter('club', $club)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents d'un club filtrés par type.
     *
     * @return Document[]
     */
    public function findByClubAndType(Club $club, string $type): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.club = :club')
            ->andWhere('d.type = :type')
            ->setParameter('club', $club)
            ->setParameter('type', $type)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents visibles pour tous les membres (VIS_MEMBRES + VIS_PARENTS).
     * Utilisé côté joueurs connectés (pas parents PIRB).
     *
     * @return Document[]
     */
    public function findVisibleMembres(Club $club): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.club = :club')
            ->andWhere('d.visibilite IN (:vis)')
            ->setParameter('club', $club)
            ->setParameter('vis', [Document::VIS_MEMBRES, Document::VIS_PARENTS])
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents visibles côté PIRB (parents + joueurs).
     * Inclut VIS_MEMBRES et VIS_PARENTS uniquement.
     *
     * @return Document[]
     */
    public function findVisiblePirb(Club $club): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.club = :club')
            ->andWhere('d.visibilite IN (:vis)')
            ->setParameter('club', $club)
            ->setParameter('vis', [Document::VIS_MEMBRES, Document::VIS_PARENTS])
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents liés à une joueuse spécifique, visibles en PIRB.
     *
     * @return Document[]
     */
    public function findByJoueurVisiblePirb(Club $club, int $joueurId): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.joueur', 'j')
            ->where('d.club = :club')
            ->andWhere('j.id = :joueurId')
            ->andWhere('d.visibilite IN (:vis)')
            ->setParameter('club', $club)
            ->setParameter('joueurId', $joueurId)
            ->setParameter('vis', [Document::VIS_MEMBRES, Document::VIS_PARENTS])
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
