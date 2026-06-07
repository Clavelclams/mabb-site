<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\OperationTresorerie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OperationTresorerie>
 *
 * Repository pour les opérations de trésorerie d'un club.
 *
 * Toutes les méthodes filtrent par CLUB en premier — multi-tenant strict.
 * Si tu ajoutes une méthode qui ne filtre pas par club, tu casses la sécurité :
 * c'est une règle d'or de ce projet.
 *
 * Les méthodes de calcul (somme, agrégation) renvoient des strings (Decimal)
 * et pas des floats : précision compta oblige. Pour additionner, utilise
 * bcadd() ou (côté template) number_format().
 */
class OperationTresorerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OperationTresorerie::class);
    }

    /**
     * Toutes les opérations d'un club, plus récentes d'abord.
     *
     * @return OperationTresorerie[]
     */
    public function findByClub(Club $club, int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.club = :club')
            ->setParameter('club', $club)
            ->orderBy('o.date', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Opérations d'un club sur une période donnée.
     *
     * @return OperationTresorerie[]
     */
    public function findByClubAndPeriode(Club $club, \DateTimeInterface $debut, \DateTimeInterface $fin): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.club = :club')
            ->andWhere('o.date BETWEEN :debut AND :fin')
            ->setParameter('club', $club)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('o.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Solde courant d'un club = somme(recettes) - somme(dépenses).
     *
     * Retourne un STRING décimal (ex: "1234.56") pour préserver la précision.
     * Le caller fait number_format() pour l'affichage si besoin.
     *
     * Implémentation SQL : on agrège côté BDD pour éviter d'hydrater 10000
     * lignes en mémoire juste pour faire +/-. Le COALESCE évite le NULL si
     * le club n'a aucune opération.
     */
    public function getSolde(Club $club): string
    {
        $recettes = $this->sumByType($club, OperationTresorerie::TYPE_RECETTE);
        $depenses = $this->sumByType($club, OperationTresorerie::TYPE_DEPENSE);

        // bcsub pour soustraction décimale précise (pas de float)
        return bcsub($recettes, $depenses, 2);
    }

    /**
     * Somme des opérations d'un club pour un type donné (RECETTE ou DEPENSE).
     * Retourne "0.00" si aucune opération.
     */
    public function sumByType(Club $club, string $type, ?\DateTimeInterface $debut = null, ?\DateTimeInterface $fin = null): string
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.montant), 0) AS total')
            ->where('o.club = :club')
            ->andWhere('o.type = :type')
            ->setParameter('club', $club)
            ->setParameter('type', $type);

        if ($debut !== null && $fin !== null) {
            $qb->andWhere('o.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut)
                ->setParameter('fin', $fin);
        }

        $result = $qb->getQuery()->getSingleScalarResult();
        return (string) $result;
    }

    /**
     * Agrégation par catégorie sur une période — pour graphes camembert.
     *
     * @return array<array{categorie: string, type: string, total: string, nb: int}>
     */
    public function sumByCategorie(Club $club, ?\DateTimeInterface $debut = null, ?\DateTimeInterface $fin = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('o.categorie AS categorie, o.type AS type, COALESCE(SUM(o.montant), 0) AS total, COUNT(o.id) AS nb')
            ->where('o.club = :club')
            ->setParameter('club', $club)
            ->groupBy('o.categorie, o.type')
            ->orderBy('total', 'DESC');

        if ($debut !== null && $fin !== null) {
            $qb->andWhere('o.date BETWEEN :debut AND :fin')
                ->setParameter('debut', $debut)
                ->setParameter('fin', $fin);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Évolution sur les N derniers mois pour graphe en barres.
     *
     * Retourne un tableau indexé par "YYYY-MM" avec recettes + dépenses.
     *
     * NOTE : on fait l'agrégation côté PHP plutôt que via GROUP BY MONTH() pour
     * que le code reste portable (MySQL/MariaDB/PostgreSQL). Si on a un jour
     * 100000 opérations, on optimisera côté SQL.
     *
     * @return array<string, array{recettes: string, depenses: string}>
     */
    public function getEvolutionMensuelle(Club $club, int $nbMois = 12): array
    {
        $debut = new \DateTimeImmutable(sprintf('first day of -%d months 00:00:00', $nbMois - 1));
        $fin   = new \DateTimeImmutable('last day of this month 23:59:59');

        $operations = $this->findByClubAndPeriode($club, $debut, $fin);

        // Initialiser tous les mois à 0 pour avoir un graphe continu
        $resultat = [];
        for ($i = $nbMois - 1; $i >= 0; $i--) {
            $key = (new \DateTimeImmutable(sprintf('first day of -%d months', $i)))->format('Y-m');
            $resultat[$key] = ['recettes' => '0.00', 'depenses' => '0.00'];
        }

        foreach ($operations as $op) {
            $key = $op->getDate()->format('Y-m');
            if (!isset($resultat[$key])) {
                continue; // hors plage
            }
            $cle = $op->getType() === OperationTresorerie::TYPE_RECETTE ? 'recettes' : 'depenses';
            $resultat[$key][$cle] = bcadd($resultat[$key][$cle], $op->getMontant(), 2);
        }

        return $resultat;
    }
}
