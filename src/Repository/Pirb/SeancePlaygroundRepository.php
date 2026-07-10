<?php

declare(strict_types=1);

namespace App\Repository\Pirb;

use App\Entity\Pirb\SeancePlayground;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SeancePlaygroundRepository — [Engagement V1, 10/07/2026]
 *
 * @extends ServiceEntityRepository<SeancePlayground>
 */
class SeancePlaygroundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeancePlayground::class);
    }

    /**
     * Le CLASSEMENT du club sur un mode, depuis une date donnée.
     *
     * Une ligne par joueuse : meilleur score, total de réussis, nombre de
     * séances — agrégé en SQL (GROUP BY), trié meilleur score d'abord.
     * On joint la fiche Joueur pour le prénom (RÈGLE PRODUIT : le classement
     * n'expose QUE le prénom + le club, jamais le nom de famille).
     *
     * @return array<array{joueurId: int, prenom: string, bestScore: int, totalReussis: int, nbSeances: int}>
     */
    public function classementClub(int $clubId, string $mode, \DateTimeImmutable $depuis, int $limite = 20): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select(
                'IDENTITY(s.joueur) AS joueurId',
                'j.prenom AS prenom',
                'MAX(s.score) AS bestScore',
                'SUM(s.reussis) AS totalReussis',
                'COUNT(s.id) AS nbSeances',
            )
            ->join('s.joueur', 'j')
            ->andWhere('j.club = :club')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.createdAt >= :depuis')
            ->andWhere('j.isActive = true')
            ->setParameter('club', $clubId)
            ->setParameter('mode', $mode)
            ->setParameter('depuis', $depuis)
            ->groupBy('s.joueur, j.prenom')
            ->orderBy('bestScore', 'DESC')
            ->addOrderBy('totalReussis', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getScalarResult();

        // Les agrégats SQL reviennent en string : on re-type proprement ici,
        // le contrôleur n'a plus qu'à sérialiser.
        return array_map(static fn(array $r) => [
            'joueurId'     => (int) $r['joueurId'],
            'prenom'       => (string) ($r['prenom'] ?? ''),
            'bestScore'    => (int) $r['bestScore'],
            'totalReussis' => (int) $r['totalReussis'],
            'nbSeances'    => (int) $r['nbSeances'],
        ], $rows);
    }

    /** Total de réussis d'une joueuse sur un mode (pour les paliers, tous temps). */
    public function totalReussis(Joueur $joueur, string $mode): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.reussis), 0)')
            ->andWhere('s.joueur = :j')
            ->andWhere('s.mode = :mode')
            ->setParameter('j', $joueur)
            ->setParameter('mode', $mode)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
