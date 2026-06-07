<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository ActionMatch — accès BDD aux actions granulaires d'un match.
 *
 * Sera enrichi en Phase 1B avec les méthodes d'agrégation et shot chart.
 * Pour Phase 1A, on déclare juste les méthodes essentielles.
 *
 * PERFORMANCE :
 *   Les méthodes de comptage utilisent des COUNT() SQL pour éviter d'hydrater
 *   en mémoire des centaines de lignes inutilement. Important quand un match
 *   peut générer 200-400 actions.
 *
 * @extends ServiceEntityRepository<ActionMatch>
 */
class ActionMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionMatch::class);
    }

    /**
     * Toutes les actions d'une rencontre, ordonnées chronologiquement.
     * Utilisé pour reconstruire le déroulé du match (momentum, sequence).
     *
     * @return ActionMatch[]
     */
    public function actionsRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->orderBy('a.quartTemps', 'ASC')
            ->addOrderBy('a.minute', 'ASC')
            ->addOrderBy('a.secondes', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les actions d'un joueur sur une rencontre donnée.
     * Utilisé par le service d'agrégation pour calculer les compteurs FIBA.
     *
     * @return ActionMatch[]
     */
    public function actionsJoueurSurRencontre(Joueur $joueur, Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.joueur = :joueur')
            ->andWhere('a.rencontre = :rencontre')
            ->setParameter('joueur', $joueur)
            ->setParameter('rencontre', $rencontre)
            ->orderBy('a.quartTemps', 'ASC')
            ->addOrderBy('a.minute', 'ASC')
            ->addOrderBy('a.secondes', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les actions de TIR d'un joueur (avec position X/Y).
     * Utilisé pour le shot chart — par match, par saison, ou sur toute la carrière.
     *
     * @param string|null $saison Optionnel, format "AAAA-AAAA" (ex: "2025-2026")
     * @return ActionMatch[]
     */
    public function actionsTirsJoueur(Joueur $joueur, ?Rencontre $rencontre = null, ?string $saison = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.joueur = :joueur')
            ->andWhere('a.type IN (:typesTir)')
            ->setParameter('joueur', $joueur)
            ->setParameter('typesTir', \App\Entity\Sport\ActionMatch::TYPES_AVEC_POSITION);

        if ($rencontre !== null) {
            $qb->andWhere('a.rencontre = :rencontre')
               ->setParameter('rencontre', $rencontre);
        }

        if ($saison !== null) {
            [$debut, $fin] = EvaluationMatchRepository::saisonBounds($saison);
            $qb->join('a.rencontre', 'r')
               ->andWhere('r.date BETWEEN :debut AND :fin')
               ->setParameter('debut', $debut)
               ->setParameter('fin', $fin);
        }

        return $qb->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptage d'actions d'un joueur sur une rencontre, groupé par type.
     *
     * Retour : ['tir_2pt_int_reussi' => 3, 'rebond_offensif' => 2, ...]
     *
     * Optimisé SQL : COUNT() + GROUP BY au lieu d'hydrater toutes les actions.
     * Indispensable pour la performance quand un match a 200+ actions.
     *
     * @return array<string, int>
     */
    public function comptageActionsParType(Joueur $joueur, Rencontre $rencontre): array
    {
        // V2.1d Étape 2.3 — Filtrage par session OFFICIELLE.
        //
        // Stratégie source-de-vérité :
        //   1. Cherche la session OFFICIELLE de la rencontre
        //   2. Si trouvée : agrège UNIQUEMENT ses actions
        //   3. Sinon : agrège les actions SANS session (données pre-V2.1d)
        //
        // Garantit que la fiche joueuse ne mélange JAMAIS les saisies de
        // plusieurs bénévoles parallèles.
        $sessionOfficielle = $this->getEntityManager()
            ->getRepository(\App\Entity\Sport\SessionStatsLive::class)
            ->findOneBy([
                'rencontre' => $rencontre,
                'statut'    => \App\Entity\Sport\SessionStatsLive::STATUT_OFFICIELLE,
            ]);

        $qb = $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) AS nb')
            ->where('a.joueur = :joueur')
            ->andWhere('a.rencontre = :rencontre')
            ->setParameter('joueur', $joueur)
            ->setParameter('rencontre', $rencontre)
            ->groupBy('a.type');

        if ($sessionOfficielle !== null) {
            $qb->andWhere('a.session = :session')
               ->setParameter('session', $sessionOfficielle);
        } else {
            // Compat ancienne : actions créées avant V2.1d (sans session)
            $qb->andWhere('a.session IS NULL');
        }

        $rows = $qb->getQuery()->getArrayResult();

        // Transforme [{type: 'X', nb: 3}, ...] en ['X' => 3, ...]
        $resultat = [];
        foreach ($rows as $row) {
            $resultat[$row['type']] = (int) $row['nb'];
        }
        return $resultat;
    }
}
