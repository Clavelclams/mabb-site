<?php

declare(strict_types=1);

namespace App\Repository\Pirb;

use App\Entity\Pirb\Follow;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * FollowRepository — [Social V1, 09/07/2026]
 *
 * Toutes les lectures du graphe social. Les compteurs sont des COUNT SQL
 * (jamais un count() PHP sur une collection chargée) : avec 200 abonnées,
 * on veut 1 requête qui renvoie « 200 », pas 200 entités hydratées.
 *
 * @extends ServiceEntityRepository<Follow>
 */
class FollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Follow::class);
    }

    /** Combien de joueuses suivent $joueur (ses abonnées). */
    public function compteAbonnes(Joueur $joueur): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.suivie = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Combien de joueuses $joueur suit (ses abonnements). */
    public function compteAbonnements(Joueur $joueur): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.suiveuse = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les joueuses qui suivent $joueur, plus récentes d'abord.
     * On renvoie directement les Joueur (pas les Follow) : c'est ce que
     * le contrôleur transforme en cartes — le Follow n'est qu'un lien.
     *
     * @return Joueur[]
     */
    public function abonnesDe(Joueur $joueur): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f', 's')
            ->join('f.suiveuse', 's')
            ->andWhere('f.suivie = :j')
            ->setParameter('j', $joueur)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn(Follow $f) => $f->getSuiveuse(), $rows);
    }

    /**
     * Les joueuses que $joueur suit, plus récentes d'abord.
     *
     * @return Joueur[]
     */
    public function abonnementsDe(Joueur $joueur): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f', 's')
            ->join('f.suivie', 's')
            ->andWhere('f.suiveuse = :j')
            ->setParameter('j', $joueur)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn(Follow $f) => $f->getSuivie(), $rows);
    }

    /**
     * Le lien « $suiveuse suit $suivie » s'il existe (sinon null).
     * Sert au toggle : présent → on supprime, absent → on crée.
     */
    public function trouverPaire(Joueur $suiveuse, Joueur $suivie): ?Follow
    {
        return $this->findOneBy(['suiveuse' => $suiveuse, 'suivie' => $suivie]);
    }

    /**
     * Les ids des joueuses que $joueur suit — pour marquer `suivie: true`
     * sur les cartes de la commu en UNE requête (pas un exists() par carte).
     *
     * @return int[]
     */
    public function idsSuiviesPar(Joueur $joueur): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.suivie) AS id')
            ->andWhere('f.suiveuse = :j')
            ->setParameter('j', $joueur)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn(array $r) => (int) $r['id'], $rows);
    }
}
