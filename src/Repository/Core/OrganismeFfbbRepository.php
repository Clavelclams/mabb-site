<?php

declare(strict_types=1);

namespace App\Repository\Core;

use App\Entity\Core\OrganismeFfbb;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganismeFfbb>
 */
class OrganismeFfbbRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganismeFfbb::class);
    }

    /**
     * Cherche un organisme officiel FFBB par son numéro de groupement.
     * Insensible à la casse / aux espaces (le numéro est stocké normalisé).
     */
    public function findOneByNumero(string $numero): ?OrganismeFfbb
    {
        return $this->findOneBy(['numero' => strtoupper(trim($numero))]);
    }

    /** Vrai si le numéro correspond à un organisme officiel FFBB. */
    public function estOfficiel(string $numero): bool
    {
        return $this->findOneByNumero($numero) !== null;
    }
}
