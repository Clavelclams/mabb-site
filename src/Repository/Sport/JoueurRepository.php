<?php

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
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

    /**
     * Cherche un Joueur non encore lié (user IS NULL) dont l'email correspond
     * à l'email du User — pour l'auto-lien au 1er login PIRB.
     *
     * Priorité : email exact → nom+prénom exacts.
     * Retourne null si aucune correspondance unique trouvée.
     */
    public function findAutoLinkByEmail(string $email): ?Joueur
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return $this->createQueryBuilder('j')
            ->where('j.user IS NULL')
            ->andWhere('j.ephemere = false OR j.ephemere IS NULL')
            ->andWhere('LOWER(j.email) = :email')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Compte les Users du club qui n'ont pas encore de Joueur lié.
     * Utile pour l'alerte admin "X comptes PIRB en attente de lien".
     *
     * @return array<array{user: User, suggestion: Joueur|null}> triés par nom
     */
    public function findUsersWithoutJoueur(\App\Entity\Sport\Club $club): array
    {
        $users = $this->getEntityManager()->getRepository(\App\Entity\Core\User::class)
            ->createQueryBuilder('u')
            ->innerJoin(\App\Entity\Core\UserClubRole::class, 'ucr', 'WITH', 'ucr.user = u AND ucr.club = :club AND ucr.status = :active')
            ->leftJoin(\App\Entity\Sport\Joueur::class, 'jl', 'WITH', 'jl.user = u AND jl.club = :club')
            ->where('jl.id IS NULL')
            ->setParameter('club', $club)
            ->setParameter('active', \App\Entity\Core\UserClubRole::STATUS_ACTIVE)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        // Pour chaque user sans joueur, chercher un joueur non-lié avec le même email
        $result = [];
        foreach ($users as $user) {
            $suggestion = null;
            if ($user->getEmail()) {
                $suggestion = $this->findAutoLinkByEmail($user->getEmail());
            }
            $result[] = ['user' => $user, 'suggestion' => $suggestion];
        }

        return $result;
    }

    /**
     * Trouve les joueurs actifs d'une équipe via les affectations (joueur_equipe),
     * indépendamment de Joueur.equipe (champ "principale" legacy).
     *
     * Inclut toutes les affectations actives pour cette équipe+saison :
     * principale, doublage, surclassement, réserve.
     *
     * À utiliser partout où on a besoin de "qui joue dans cette équipe cette saison ?"
     * — notamment le pipeline OCR FFBB.
     */
    public function findByEquipeAffectation(Equipe $equipe, string $saison): array
    {
        return $this->createQueryBuilder('j')
            ->join('j.affectations', 'a')
            ->where('a.equipe = :equipe')
            ->andWhere('a.saison = :saison')
            ->andWhere('j.isActive = :active')
            ->andWhere('a.actif = :aactif')
            ->setParameter('equipe', $equipe)
            ->setParameter('saison', $saison)
            ->setParameter('active', true)
            ->setParameter('aactif', true)
            ->getQuery()
            ->getResult();
    }
}
