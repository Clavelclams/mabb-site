<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\DemandeAccesPdf;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemandeAccesPdf>
 */
class DemandeAccesPdfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandeAccesPdf::class);
    }

    /**
     * Récupère la demande unique d'une joueuse pour un document donné.
     * Null si elle n'a jamais demandé ce document.
     */
    public function findOneDemande(Joueur $joueur, Rencontre $rencontre, string $typePdf): ?DemandeAccesPdf
    {
        return $this->findOneBy([
            'joueur'    => $joueur,
            'rencontre' => $rencontre,
            'typePdf'   => $typePdf,
        ]);
    }

    /**
     * Toutes les demandes d'une joueuse pour un match donné.
     * Utilisé dans le template pour afficher le statut de chaque bouton PDF.
     *
     * @return array<string, DemandeAccesPdf>  clé = typePdf ('feuille', 'resume', 'positions')
     */
    public function findDemandesParMatch(Joueur $joueur, Rencontre $rencontre): array
    {
        $demandes = $this->createQueryBuilder('d')
            ->where('d.joueur = :j')
            ->andWhere('d.rencontre = :r')
            ->setParameter('j', $joueur)
            ->setParameter('r', $rencontre)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($demandes as $d) {
            $indexed[$d->getTypePdf()] = $d;
        }
        return $indexed;
    }

    /**
     * Toutes les demandes PENDING pour les équipes d'un coach.
     * Utilisé dans Manager pour que le coach gère ses demandes en attente.
     *
     * @param  Equipe[]  $equipes  Équipes coachées par le user
     * @return DemandeAccesPdf[]
     */
    public function findPendingParEquipes(array $equipes): array
    {
        if (empty($equipes)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->join('d.joueur', 'j')
            ->addSelect('j')
            ->join('d.rencontre', 'r')
            ->addSelect('r')
            ->where('d.statut = :pending')
            ->andWhere('j.equipe IN (:equipes)')
            ->setParameter('pending', DemandeAccesPdf::STATUT_PENDING)
            ->setParameter('equipes', $equipes)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les demandes (tous statuts) pour les équipes d'un coach,
     * triées récentes en premier. Utilisé dans la vue historique.
     *
     * @param  Equipe[]  $equipes
     * @return DemandeAccesPdf[]
     */
    public function findToutesParEquipes(array $equipes, int $limit = 50): array
    {
        if (empty($equipes)) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->join('d.joueur', 'j')
            ->addSelect('j')
            ->join('d.rencontre', 'r')
            ->addSelect('r')
            ->where('j.equipe IN (:equipes)')
            ->setParameter('equipes', $equipes)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les demandes PENDING pour un coach (badge notification).
     * @param  Equipe[]  $equipes
     */
    public function countPendingParEquipes(array $equipes): int
    {
        if (empty($equipes)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->join('d.joueur', 'j')
            ->where('d.statut = :pending')
            ->andWhere('j.equipe IN (:equipes)')
            ->setParameter('pending', DemandeAccesPdf::STATUT_PENDING)
            ->setParameter('equipes', $equipes)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
