<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\ResponsableLegal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResponsableLegal>
 */
class ResponsableLegalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResponsableLegal::class);
    }

    /** @return ResponsableLegal[] */
    public function findByJoueur(Joueur $joueur): array
    {
        return $this->findBy(['joueur' => $joueur], ['id' => 'ASC']);
    }

    /**
     * Doublon probable : même joueuse + même nom complet (comparaison
     * insensible à la casse). Utilisé par l'import pour l'idempotence.
     */
    public function existePour(Joueur $joueur, string $nomComplet): bool
    {
        return (bool) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.joueur = :j')->setParameter('j', $joueur)
            ->andWhere('LOWER(r.nomComplet) = :n')->setParameter('n', mb_strtolower(trim($nomComplet)))
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
