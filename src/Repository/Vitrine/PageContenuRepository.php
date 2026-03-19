<?php

namespace App\Repository\Vitrine;

use App\Entity\Vitrine\PageContenu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PageContenuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageContenu::class);
    }

    public function findBySlug(string $slug): ?PageContenu
    {
        return $this->findOneBy(['pageSlug' => $slug]);
    }
}
