<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\PreInscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PreInscription>
 */
class PreInscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreInscription::class);
    }

    /** @return PreInscription[] */
    public function findByClubEtStatut(Club $club, ?string $statut = null): array
    {
        $criteres = ['club' => $club];
        if ($statut !== null && in_array($statut, PreInscription::STATUTS, true)) {
            $criteres['statut'] = $statut;
        }
        return $this->findBy($criteres, ['createdAt' => 'DESC']);
    }

    public function compterNouvelles(Club $club): int
    {
        return $this->count(['club' => $club, 'statut' => PreInscription::STATUT_NOUVELLE]);
    }

    /**
     * Anti-spam basique du formulaire public : nombre de dépôts récents
     * pour un même club (limite globale, pas par IP — pas de stockage IP,
     * minimisation RGPD).
     */
    public function compterRecentes(Club $club, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.club = :club')->setParameter('club', $club)
            ->andWhere('p.createdAt >= :depuis')->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }
}
