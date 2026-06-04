<?php

namespace App\Repository\Sport;

use App\Entity\Sport\JoueurBadge;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JoueurBadge>
 */
class JoueurBadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurBadge::class);
    }

    /**
     * Retourne les codes de tous les badges déjà débloqués par un joueur
     * pour une saison donnée (ou hors-saison si saison=null).
     *
     * @return string[]
     */
    public function codesBadgesPourJoueur(Joueur $joueur, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.codeBadge')
            ->andWhere('b.joueur = :joueur')->setParameter('joueur', $joueur);

        if ($saison !== null) {
            // On veut TOUS les badges qui touchent ce joueur : ceux de la saison
            // ET ceux hors-saison (one-shot à vie).
            $qb->andWhere('b.saison = :saison OR b.saison IS NULL')
               ->setParameter('saison', $saison);
        }

        $rows = $qb->getQuery()->getScalarResult();
        return array_column($rows, 'codeBadge');
    }

    /**
     * Retourne les badges débloqués par un joueur, ordonnés par date desc.
     *
     * @return JoueurBadge[]
     */
    public function badgesPourJoueur(Joueur $joueur, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.joueur = :joueur')->setParameter('joueur', $joueur)
            ->orderBy('b.debloqueAt', 'DESC');

        if ($saison !== null) {
            $qb->andWhere('b.saison = :saison OR b.saison IS NULL')
               ->setParameter('saison', $saison);
        }

        return $qb->getQuery()->getResult();
    }
}
