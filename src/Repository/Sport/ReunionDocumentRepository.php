<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\ReunionDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReunionDocument>
 */
class ReunionDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReunionDocument::class);
    }
}
