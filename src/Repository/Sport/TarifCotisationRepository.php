<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\TarifCotisation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TarifCotisation>
 */
class TarifCotisationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TarifCotisation::class);
    }

    /**
     * Tous les tarifs d'un club pour une saison donnée.
     *
     * @return TarifCotisation[]
     */
    public function findByClubAndSaison(Club $club, string $saison): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.club = :club')
            ->andWhere('t.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->orderBy('t.categorie', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Construit une MAP catégorie → montant pour un club + saison.
     * Pratique pour le générateur de cotisations : O(1) lookup par catégorie
     * au lieu d'une boucle.
     *
     * @return array<string, string>  Ex: ['U13' => '180.00', 'U15' => '200.00']
     */
    public function getMapCategorieMontant(Club $club, string $saison): array
    {
        $tarifs = $this->findByClubAndSaison($club, $saison);
        $map = [];
        foreach ($tarifs as $tarif) {
            $map[$tarif->getCategorie()] = $tarif->getMontant();
        }
        return $map;
    }

    /**
     * Retourne le tarif d'une catégorie précise (ou null si pas défini).
     */
    public function findOneByClubCategorieSaison(Club $club, string $categorie, string $saison): ?TarifCotisation
    {
        return $this->findOneBy([
            'club'      => $club,
            'categorie' => $categorie,
            'saison'    => $saison,
        ]);
    }
}
