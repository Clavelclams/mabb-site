<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\CotisationJoueur;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CotisationJoueur>
 *
 * Repository des cotisations joueurs. Multi-tenant via JOIN sur Joueur.club
 * (la cotisation n'a pas de FK club directe, c'est délégué au joueur).
 */
class CotisationJoueurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CotisationJoueur::class);
    }

    /**
     * UNE cotisation max par joueur+saison — utile pour vérifier doublons avant
     * création (et pour la fiche joueuse en lecture).
     */
    public function findByJoueurAndSaison(Joueur $joueur, string $saison): ?CotisationJoueur
    {
        return $this->findOneBy(['joueur' => $joueur, 'saison' => $saison]);
    }

    /**
     * Cotisation d'un joueur pour la SAISON COURANTE (sept→août).
     * Utilisé par la fiche joueuse pour afficher "Ma cotisation".
     */
    public function findCouranteByJoueur(Joueur $joueur): ?CotisationJoueur
    {
        return $this->findByJoueurAndSaison($joueur, CotisationJoueur::getSaisonCourante());
    }

    /**
     * Toutes les cotisations d'un joueur (toutes saisons) — pour historique.
     *
     * @return CotisationJoueur[]
     */
    public function findAllByJoueur(Joueur $joueur): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.joueur = :joueur')
            ->setParameter('joueur', $joueur)
            ->orderBy('c.saison', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les cotisations d'un club pour une saison donnée.
     * Trié : A_PAYER d'abord (urgent), ECHEANCIER, PAYEE, EXEMPTEE.
     *
     * @return CotisationJoueur[]
     */
    public function findByClubAndSaison(Club $club, string $saison): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.joueur', 'j')
            ->where('j.club = :club')
            ->andWhere('c.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            // Tri par statut via CASE WHEN
            ->addSelect("CASE
                WHEN c.statut = 'A_PAYER' THEN 0
                WHEN c.statut = 'ECHEANCIER' THEN 1
                WHEN c.statut = 'PAYEE' THEN 2
                ELSE 3
            END AS HIDDEN ordre")
            ->orderBy('ordre', 'ASC')
            ->addOrderBy('j.nom', 'ASC')
            ->addOrderBy('j.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compteurs par statut pour les KPIs dashboard.
     *
     * @return array<string, int> Ex: ['A_PAYER' => 12, 'PAYEE' => 45, ...]
     */
    public function countByStatutForClubAndSaison(Club $club, string $saison): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.statut AS statut, COUNT(c.id) AS nb')
            ->innerJoin('c.joueur', 'j')
            ->where('j.club = :club')
            ->andWhere('c.saison = :saison')
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->groupBy('c.statut')
            ->getQuery()
            ->getResult();

        // Initialiser tous les statuts à 0 pour avoir une réponse complète
        $counts = array_fill_keys(CotisationJoueur::STATUTS, 0);
        foreach ($result as $row) {
            $counts[$row['statut']] = (int) $row['nb'];
        }
        return $counts;
    }

    /**
     * Total attendu et total perçu pour une saison.
     *
     * @return array{attendu: string, percu: string}
     */
    public function getTotauxForClubAndSaison(Club $club, string $saison): array
    {
        $row = $this->createQueryBuilder('c')
            ->select(
                'COALESCE(SUM(c.montantAttendu), 0) AS attendu',
                'COALESCE(SUM(c.montantPaye), 0) AS percu'
            )
            ->innerJoin('c.joueur', 'j')
            ->where('j.club = :club')
            ->andWhere('c.saison = :saison')
            ->andWhere('c.statut != :exemptee') // les exemptions ne comptent pas dans l'attendu
            ->setParameter('club', $club)
            ->setParameter('saison', $saison)
            ->setParameter('exemptee', CotisationJoueur::STATUT_EXEMPTEE)
            ->getQuery()
            ->getSingleResult();

        return [
            'attendu' => (string) $row['attendu'],
            'percu'   => (string) $row['percu'],
        ];
    }

    /**
     * IDs des joueurs actifs d'un club qui N'ONT PAS encore de cotisation
     * pour la saison donnée. Utilisé par le générateur pour éviter doublons.
     *
     * @return int[]
     */
    public function findJoueursActifsSansCotisation(Club $club, string $saison): array
    {
        // Étape 1 : tous les joueurs actifs du club
        $joueurs = $this->getEntityManager()->createQueryBuilder()
            ->select('j.id')
            ->from(Joueur::class, 'j')
            ->where('j.club = :club')
            ->andWhere('j.isActive = true')
            ->setParameter('club', $club)
            ->getQuery()
            ->getResult();

        if (empty($joueurs)) {
            return [];
        }
        $idsActifs = array_column($joueurs, 'id');

        // Étape 2 : ceux qui ont déjà une cotisation pour la saison
        $dejaCotisants = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.joueur) AS joueur_id')
            ->where('c.saison = :saison')
            ->andWhere('IDENTITY(c.joueur) IN (:ids)')
            ->setParameter('saison', $saison)
            ->setParameter('ids', $idsActifs)
            ->getQuery()
            ->getResult();

        $idsDejaCotisants = array_column($dejaCotisants, 'joueur_id');

        // Étape 3 : la différence
        return array_values(array_diff($idsActifs, $idsDejaCotisants));
    }
}
