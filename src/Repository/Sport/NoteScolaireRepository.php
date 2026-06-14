<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\NoteScolaire;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NoteScolaire>
 */
class NoteScolaireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteScolaire::class);
    }
}
