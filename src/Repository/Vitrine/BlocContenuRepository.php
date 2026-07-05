<?php

declare(strict_types=1);

namespace App\Repository\Vitrine;

use App\Entity\Vitrine\BlocContenu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BlocContenu>
 */
class BlocContenuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlocContenu::class);
    }

    /** @return array<string, BlocContenu> indexé par clé */
    public function toutIndexeParCle(): array
    {
        $result = [];
        foreach ($this->findAll() as $bloc) {
            $result[$bloc->getCle()] = $bloc;
        }
        return $result;
    }

    /** @return array<string, BlocContenu[]> groupé par page, trié */
    public function groupesParPage(): array
    {
        $groupes = [];
        foreach ($this->findBy([], ['cle' => 'ASC']) as $bloc) {
            $groupes[$bloc->getPage()][] = $bloc;
        }
        ksort($groupes);
        return $groupes;
    }
}
