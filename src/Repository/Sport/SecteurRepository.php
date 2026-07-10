<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\Secteur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Secteur>
 */
class SecteurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Secteur::class);
    }

    /** @return Secteur[] */
    public function findByClub(Club $club): array
    {
        return $this->findBy(['club' => $club], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }

    public function findOneByClubEtNom(Club $club, string $nom): ?Secteur
    {
        return $this->findOneBy(['club' => $club, 'nom' => mb_strtoupper(trim($nom))]);
    }
}
