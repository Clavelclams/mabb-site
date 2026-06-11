<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\FeedbackSeance;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedbackSeance>
 */
class FeedbackSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackSeance::class);
    }

    public function findExistantForJoueurSeance(Joueur $joueur, Seance $seance): ?FeedbackSeance
    {
        return $this->createQueryBuilder('f')
            ->where('f.joueur = :j')
            ->andWhere('f.seance = :s')
            ->setParameter('j', $joueur)
            ->setParameter('s', $seance)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Stats pour une séance (coach voit moyenne + distribution + commentaires).
     * Retourne :
     *   - moyenne (float)
     *   - nb_votes (int)
     *   - distribution (int[]) : [0=>n, 1=>n, ..., 5=>n]
     *   - commentaires (array{note:int, commentaire:string|null, est_anonyme:bool}[])
     */
    public function statsForSeance(Seance $seance): array
    {
        $feedbacks = $this->createQueryBuilder('f')
            ->where('f.seance = :s')
            ->setParameter('s', $seance)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $distribution = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $total = 0;
        $somme = 0;
        $commentaires = [];

        /** @var FeedbackSeance $f */
        foreach ($feedbacks as $f) {
            $n = $f->getNote();
            $distribution[$n]++;
            $somme += $n;
            $total++;
            if ($f->getCommentaire() !== null) {
                $commentaires[] = [
                    'note'        => $n,
                    'commentaire' => $f->getCommentaire(),
                    'est_anonyme' => $f->isAnonyme(),
                ];
            }
        }

        return [
            'moyenne'      => $total > 0 ? round($somme / $total, 2) : null,
            'nb_votes'     => $total,
            'distribution' => $distribution,
            'commentaires' => $commentaires,
        ];
    }

    /** Compte combien de feedbacks un joueur a déjà postés (pour badge). */
    public function countByJoueur(Joueur $joueur): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.joueur = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
