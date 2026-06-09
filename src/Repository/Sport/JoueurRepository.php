<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Joueur>
 */
class JoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Joueur::class);
    }

    /** Multi-tenant : ne retourne que les joueur du club. */
    public function findByClub(int $clubId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.club = :club')
            ->setParameter('club', $clubId)
            ->getQuery()
            ->getResult();
    }

    /**
     * V1.4a — Cherche des Users CLUB_MEMBER actifs du club, candidats au lien
     * avec la fiche Joueur donnée. Exclut les users déjà liés à une autre
     * fiche Joueur du même club.
     *
     * Tri par pertinence : email match (+100), nom (+30), prenom (+30).
     *
     * @return array<int, array{user: \App\Entity\Core\User, score: int}>
     */
    public function findCandidatsLinkUser(Joueur $joueur, ?string $recherche = null): array
    {
        $club = $joueur->getClub();
        if ($club === null) {
            return [];
        }

        $qb = $this->getEntityManager()->getRepository(\App\Entity\Core\User::class)
            ->createQueryBuilder('u')
            ->innerJoin(\App\Entity\Core\UserClubRole::class, 'ucr', 'WITH', 'ucr.user = u AND ucr.club = :club AND ucr.status = :active')
            ->leftJoin(\App\Entity\Sport\Joueur::class, 'jl', 'WITH', 'jl.user = u AND jl.club = :club AND jl.id != :moi')
            ->andWhere('jl.id IS NULL')
            ->setParameter('club', $club)
            ->setParameter('active', \App\Entity\Core\UserClubRole::STATUS_ACTIVE)
            ->setParameter('moi', $joueur->getId() ?? 0)
            ->groupBy('u.id')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->setMaxResults(20);

        if ($recherche !== null && trim($recherche) !== '') {
            $r = '%' . strtolower(trim($recherche)) . '%';
            $qb->andWhere('LOWER(u.email) LIKE :r OR LOWER(u.nom) LIKE :r OR LOWER(u.prenom) LIKE :r')
               ->setParameter('r', $r);
        }

        $users = $qb->getQuery()->getResult();

        $emailJoueur  = $joueur->getEmail() ? strtolower($joueur->getEmail()) : null;
        $nomJoueur    = strtolower($joueur->getNom() ?? '');
        $prenomJoueur = strtolower($joueur->getPrenom() ?? '');

        $resultats = [];
        foreach ($users as $u) {
            $score = 0;
            if ($emailJoueur !== null && $u->getEmail() && strtolower($u->getEmail()) === $emailJoueur) {
                $score += 100;
            }
            if ($u->getNom() && strtolower($u->getNom()) === $nomJoueur) {
                $score += 30;
            }
            if ($u->getPrenom() && strtolower($u->getPrenom()) === $prenomJoueur) {
                $score += 30;
            }
            $resultats[] = ['user' => $u, 'score' => $score];
        }

        // Tri par pertinence décroissante (suggestions en premier)
        usort($resultats, fn($a, $b) => $b['score'] <=> $a['score']);

        return $resultats;
    }
}
