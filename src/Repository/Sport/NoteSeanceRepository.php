<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\NoteSeance;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NoteSeance> */
class NoteSeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NoteSeance::class);
    }

    /** Note d'une joueuse pour une séance donnée (null si pas encore noté). */
    public function findMaNote(Joueur $joueur, Seance $seance): ?NoteSeance
    {
        return $this->findOneBy(['joueur' => $joueur, 'seance' => $seance]);
    }

    /**
     * Map des notes d'une joueuse pour une liste de séances.
     * Retourne [seance_id => NoteSeance] pour affichage rapide dans le template.
     *
     * @param  Seance[]  $seances
     * @return array<int, NoteSeance>
     */
    public function findMesNotesMap(Joueur $joueur, array $seances): array
    {
        if (empty($seances)) {
            return [];
        }

        $notes = $this->createQueryBuilder('n')
            ->where('n.joueur = :j')
            ->andWhere('n.seance IN (:seances)')
            ->setParameter('j', $joueur)
            ->setParameter('seances', $seances)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($notes as $note) {
            $map[$note->getSeance()->getId()] = $note;
        }
        return $map;
    }

    /**
     * Stats anonymes pour le coach : moyenne + distribution + commentaires.
     *
     * @return array{
     *   total: int,
     *   moyenne: float,
     *   distribution: array<int, int>,
     *   commentaires: string[]
     * }
     */
    public function getStatsAnonymesSeance(Seance $seance): array
    {
        $notes = $this->findBy(['seance' => $seance]);

        if (empty($notes)) {
            return [
                'total'         => 0,
                'moyenne'       => 0.0,
                'distribution'  => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                'commentaires'  => [],
            ];
        }

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $commentaires = [];
        $somme = 0;

        foreach ($notes as $note) {
            $distribution[$note->getNote()] = ($distribution[$note->getNote()] ?? 0) + 1;
            $somme += $note->getNote();
            if ($note->getCommentaire()) {
                $commentaires[] = $note->getCommentaire();
            }
        }

        // Mélanger les commentaires pour renforcer l'anonymat
        shuffle($commentaires);

        return [
            'total'        => count($notes),
            'moyenne'      => round($somme / count($notes), 1),
            'distribution' => $distribution,
            'commentaires' => $commentaires,
        ];
    }
}
