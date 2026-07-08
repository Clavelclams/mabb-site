<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rencontre>
 */
class RencontreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rencontre::class);
    }

    /** Multi-tenant : ne retourne que les rencontres du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres du club triées par date décroissante (plus récente en premier),
     * avec JOIN equipe pour éviter les N+1 requêtes dans la page Stats Live.
     *
     * @return Rencontre[]
     */
    public function findByClubOrderedDesc(int $clubId): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres du club POUR UNE SAISON donnée, triées par date décroissante,
     * avec JOIN equipe (anti N+1) — variante « saison » de findByClubOrderedDesc()
     * utilisée par la page Stats Live.
     *
     * Filtrage par PLAGE DE DATES (01/07 → 01/07) et NON par la colonne
     * r.saison : celle-ci est nullable (souvent vide sur les rencontres créées
     * à la main), la date fait donc foi — même règle que
     * JoueurStatsAggregator::statsSaison() et SaisonService (bascule 1er juillet).
     * Effet de bord assumé : une rencontre sans date ne peut appartenir à aucune
     * saison et n'apparaît donc pas dans la vue filtrée.
     *
     * @return Rencontre[]
     */
    public function findByClubAndSaisonOrderedDesc(int $clubId, string $saison): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        // Saison "YYYY-YYYY" → [YYYY-07-01, (YYYY+1)-07-01[. Si le libellé est
        // invalide (ne devrait pas arriver, il vient de SaisonService), on ne
        // filtre pas plutôt que de renvoyer une liste vide trompeuse.
        if (preg_match('/^(\d{4})-(\d{4})$/', $saison, $m)) {
            $qb->andWhere('r.date >= :saisonDebut')
               ->andWhere('r.date < :saisonFin')
               ->setParameter('saisonDebut', new \DateTimeImmutable($m[1] . '-07-01'))
               ->setParameter('saisonFin',   new \DateTimeImmutable($m[2] . '-07-01'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Retourne les rencontres du club qui ont au moins un PDF FFBB uploadé.
     * Utilisé dans l'ENT pour la section "PDFs officiels FFBB".
     * Triées par date décroissante (match le plus récent en premier).
     *
     * @return Rencontre[]
     */
    public function findWithPdfsByClub(int $clubId): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            // Parenthèses OBLIGATOIRES autour du OR : sans elles, la précédence
            // SQL (AND avant OR) court-circuite le filtre club → fuite multi-tenant
            // potentielle (rencontres d'autres clubs). Corrigé le 08/07.
            ->andWhere(
                '(r.resumePath IS NOT NULL OR r.feuilleMatchPath IS NOT NULL OR r.positionsTirsPath IS NOT NULL)'
            )
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rencontres du club AVEC PDF FFBB, filtrées par SAISON (plage de dates) —
     * variante « saison » de findWithPdfsByClub() pour l'ENT.
     *
     * En 2026-2027, on ne voit plus les PDF officiels des saisons passées, sauf
     * en changeant la saison active. Filtrage par PLAGE DE DATES (01/07 → 01/07),
     * même règle que SaisonService et Stats Live (la colonne r.saison est
     * nullable/peu fiable sur les rencontres créées à la main).
     *
     * @return Rencontre[]
     */
    public function findWithPdfsByClubAndSaison(int $clubId, string $saison): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.club = :club')
            ->andWhere(
                '(r.resumePath IS NOT NULL OR r.feuilleMatchPath IS NOT NULL OR r.positionsTirsPath IS NOT NULL)'
            )
            ->setParameter('club', $clubId)
            ->orderBy('r.date', 'DESC');

        if (preg_match('/^(\d{4})-(\d{4})$/', $saison, $m)) {
            $qb->andWhere('r.date >= :saisonDebut')
               ->andWhere('r.date < :saisonFin')
               ->setParameter('saisonDebut', new \DateTimeImmutable($m[1] . '-07-01'))
               ->setParameter('saisonFin',   new \DateTimeImmutable($m[2] . '-07-01'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Rencontres d'une saison ayant un PDF positiontir_*.pdf uploadé.
     * Utilisé par ProcessPositionsTirsCommand pour le traitement en lot.
     *
     * @return Rencontre[]
     */
    public function findBySaisonWithPositionsPdf(string $saison): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.equipe', 'eq')->addSelect('eq')
            ->where('r.saison = :saison')
            ->andWhere('r.positionsTirsPath IS NOT NULL')
            ->setParameter('saison', $saison)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
