<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\PresenceTerrain;
use App\Entity\Sport\SessionStatsLive;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\EvaluationMatchRepository;
use App\Repository\Sport\PresenceTerrainRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ActionMatchAggregator — pont entre saisie LIVE et stats FIBA agrégées.
 *
 * RÔLE :
 *   Convertit une suite d'ActionMatch (saisie live granulaire) en
 *   compteurs FIBA agrégés (EvaluationMatch). Permet :
 *     1. D'afficher les stats agrégées sur la fiche joueuse
 *     2. De générer un PDF résumé style FFBB
 *     3. D'archiver/figer un match terminé
 *
 * COHABITATION DES 2 SOURCES :
 *   - Si un match a des ActionMatch → on agrège depuis là (vérité granulaire)
 *   - Si un match n'a QUE EvaluationMatch (saisie manuelle/import) → on garde
 *     l'EvaluationMatch existante (la saisie live n'a jamais eu lieu)
 *
 * IDEMPOTENCE :
 *   Appeler genererEvaluationMatch() plusieurs fois pour la même paire
 *   (joueur, rencontre) → soit on update l'existante, soit on en crée une.
 *   Pas de doublon. Sûr à appeler après chaque saisie d'action.
 *
 * PERFORMANCE :
 *   Méthode agreger() utilise comptageActionsParType() qui fait un seul
 *   COUNT GROUP BY au lieu d'hydrater toutes les actions. ~10ms pour
 *   un joueur qui a 30 actions sur un match.
 */
final class ActionMatchAggregator
{
    public function __construct(
        private readonly ActionMatchRepository $actionMatchRepository,
        private readonly EvaluationMatchRepository $evaluationMatchRepository,
        private readonly PresenceTerrainRepository $presenceTerrainRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Agrège les ActionMatch d'un joueur sur une rencontre en compteurs FIBA.
     *
     * Retourne un array prêt à être appliqué à une EvaluationMatch
     * (via setters successifs ou hydratation).
     *
     * @return array{
     *     tirs2ptsReussis: int, tirs2ptsTentes: int,
     *     tirs3ptsReussis: int, tirs3ptsTentes: int,
     *     lancersReussis: int, lancersTentes: int,
     *     rebondsOffensifs: int, rebondsDefensifs: int,
     *     passesDecisives: int, interceptions: int,
     *     contres: int, contresSubis: int,
     *     fautesCommises: int, fautesProvoquees: int,
     *     pertesBalle: int,
     *     minutesJouees: int, isStarter: bool,
     * }
     */
    public function agreger(Joueur $joueur, Rencontre $rencontre): array
    {
        // Un seul COUNT GROUP BY au lieu de hydrater toutes les actions
        $comptages = $this->actionMatchRepository->comptageActionsParType($joueur, $rencontre);

        // Calcul des tirs 2pts : intérieurs (raquette) + extérieurs (mi-distance)
        // Reflète le découpage Easy Stats / FFBB
        $t2IntReussis = $comptages[ActionMatch::TYPE_TIR_2PT_INT_REUSSI] ?? 0;
        $t2IntRates   = $comptages[ActionMatch::TYPE_TIR_2PT_INT_RATE]   ?? 0;
        $t2ExtReussis = $comptages[ActionMatch::TYPE_TIR_2PT_EXT_REUSSI] ?? 0;
        $t2ExtRates   = $comptages[ActionMatch::TYPE_TIR_2PT_EXT_RATE]   ?? 0;

        $tirs2ptsReussis = $t2IntReussis + $t2ExtReussis;
        $tirs2ptsTentes  = $tirs2ptsReussis + $t2IntRates + $t2ExtRates;

        $tirs3ptsReussis = $comptages[ActionMatch::TYPE_TIR_3PT_REUSSI] ?? 0;
        $tirs3ptsTentes  = $tirs3ptsReussis + ($comptages[ActionMatch::TYPE_TIR_3PT_RATE] ?? 0);

        $lancersReussis = $comptages[ActionMatch::TYPE_LANCER_REUSSI] ?? 0;
        $lancersTentes  = $lancersReussis + ($comptages[ActionMatch::TYPE_LANCER_RATE] ?? 0);

        // [V2.4o] Minutes jouées et titulaire : lus depuis PresenceTerrain
        // (les vraies entrées/sorties en secondes, filtrées par la session
        // officielle — même source que le résumé de match). Les présences
        // sont chargées UNE fois et partagées entre les deux calculs.
        $session   = $this->sessionOfficielle($rencontre);
        $presences = $this->presenceTerrainRepository->findPourAgregation($joueur, $rencontre, $session);

        $minutesJouees = $this->calculerMinutesJouees($joueur, $rencontre, $presences, $session);
        $isStarter     = $this->detecterTitulaire($joueur, $rencontre, $presences);

        return [
            'tirs2ptsReussis'   => $tirs2ptsReussis,
            'tirs2ptsTentes'    => $tirs2ptsTentes,
            'tirs3ptsReussis'   => $tirs3ptsReussis,
            'tirs3ptsTentes'    => $tirs3ptsTentes,
            'lancersReussis'    => $lancersReussis,
            'lancersTentes'     => $lancersTentes,
            'rebondsOffensifs'  => $comptages[ActionMatch::TYPE_REBOND_OFFENSIF]  ?? 0,
            'rebondsDefensifs'  => $comptages[ActionMatch::TYPE_REBOND_DEFENSIF]  ?? 0,
            'passesDecisives'   => $comptages[ActionMatch::TYPE_PASSE_DECISIVE]   ?? 0,
            'interceptions'     => $comptages[ActionMatch::TYPE_INTERCEPTION]     ?? 0,
            'contres'           => $comptages[ActionMatch::TYPE_CONTRE]           ?? 0,
            'contresSubis'      => $comptages[ActionMatch::TYPE_CONTRE_SUBI]      ?? 0,
            'fautesCommises'    => $comptages[ActionMatch::TYPE_FAUTE_COMMISE]    ?? 0,
            'fautesProvoquees'  => $comptages[ActionMatch::TYPE_FAUTE_PROVOQUEE]  ?? 0,
            'pertesBalle'       => $comptages[ActionMatch::TYPE_PERTE_BALLE]      ?? 0,
            'minutesJouees'     => $minutesJouees,
            'isStarter'         => $isStarter,
        ];
    }

    /**
     * Génère ou met à jour l'EvaluationMatch d'un joueur sur une rencontre
     * depuis ses ActionMatch.
     *
     * Idempotent : si une EvaluationMatch existe déjà pour la paire (joueur, rencontre),
     * on l'UPDATE. Sinon on en crée une nouvelle.
     *
     * Le caller doit appeler $em->flush() après — le service ne flush pas
     * lui-même (pattern de séparation des responsabilités).
     *
     * @return EvaluationMatch L'entité créée ou mise à jour (non flushée)
     */
    public function genererEvaluationMatch(Joueur $joueur, Rencontre $rencontre): EvaluationMatch
    {
        $eval = $this->evaluationMatchRepository->findOneByJoueurAndRencontre($joueur, $rencontre);
        $estNouvelle = ($eval === null);

        if ($estNouvelle) {
            $eval = new EvaluationMatch();
            $eval->setJoueur($joueur);
            $eval->setRencontre($rencontre);
        }

        $agg = $this->agreger($joueur, $rencontre);

        // [V2.4p] Cette éval est désormais générée depuis Stats Live — même
        // si elle avait été créée par un import FFBB avant, ses valeurs
        // viennent maintenant de la source la plus riche.
        $eval->setSource(EvaluationMatch::SOURCE_LIVE);

        $eval->setIsStarter($agg['isStarter']);
        $eval->setMinutesJouees($agg['minutesJouees']);
        $eval->setTirs2ptsReussis($agg['tirs2ptsReussis']);
        $eval->setTirs2ptsTentes($agg['tirs2ptsTentes']);
        $eval->setTirs3ptsReussis($agg['tirs3ptsReussis']);
        $eval->setTirs3ptsTentes($agg['tirs3ptsTentes']);
        $eval->setLancersReussis($agg['lancersReussis']);
        $eval->setLancersTentes($agg['lancersTentes']);
        $eval->setRebondsOffensifs($agg['rebondsOffensifs']);
        $eval->setRebondsDefensifs($agg['rebondsDefensifs']);
        $eval->setPassesDecisives($agg['passesDecisives']);
        $eval->setInterceptions($agg['interceptions']);
        $eval->setContres($agg['contres']);
        $eval->setContresSubis($agg['contresSubis']);
        $eval->setFautesCommises($agg['fautesCommises']);
        $eval->setFautesProvoquees($agg['fautesProvoquees']);
        $eval->setPertesBalle($agg['pertesBalle']);

        if ($estNouvelle) {
            $this->em->persist($eval);
        }

        return $eval;
    }

    /**
     * Régénère les EvaluationMatch pour TOUTES les joueuses d'une rencontre.
     *
     * Utilisé typiquement à la fin du match (clic "Terminer le match") pour
     * figer les stats agrégées prêtes à l'export PDF.
     *
     * @return EvaluationMatch[] Tableau des évals générées
     */
    public function regenererToutesPourRencontre(Rencontre $rencontre): array
    {
        // Récupère les joueuses qui ont au moins une ActionMatch sur ce match
        // (pas toutes les joueuses du club, juste celles qui ont joué)
        $joueurs = $this->em->createQueryBuilder()
            ->select('DISTINCT j')
            ->from(Joueur::class, 'j')
            ->join(ActionMatch::class, 'a', 'WITH', 'a.joueur = j')
            ->where('a.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->getQuery()
            ->getResult();

        $evals = [];
        foreach ($joueurs as $joueur) {
            $evals[] = $this->genererEvaluationMatch($joueur, $rencontre);
        }

        $this->em->flush();
        return $evals;
    }

    // ====================================================================
    // PRIVATE — helpers de calcul temps
    // ====================================================================

    /**
     * [V2.4o] Session OFFICIELLE de la rencontre, ou null.
     * Même résolution que ActionMatchRepository::comptageActionsParType —
     * les minutes et les compteurs FIBA lisent la MÊME session, sinon on
     * afficherait « 12 pts en 0 min ».
     */
    private function sessionOfficielle(Rencontre $rencontre): ?SessionStatsLive
    {
        return $this->em->getRepository(SessionStatsLive::class)->findOneBy([
            'rencontre' => $rencontre,
            'statut'    => SessionStatsLive::STATUT_OFFICIELLE,
        ]);
    }

    /**
     * [V2.4o] Minutes jouées RÉELLES, depuis PresenceTerrain.
     *
     * AVANT : « nombre de quart-temps où la joueuse a une action × 10 min ».
     * Une joueuse qui faisait UN rebond par quart était créditée de 40 min ;
     * une remplaçante entrée 2 min en QT4 recevait 10 min. Perf Éval était
     * calculée sur ces valeurs — d'où des moyennes/min absurdes.
     *
     * MAINTENANT : SUM(sortie − entrée) sur les présences de la session
     * officielle (temps ABSOLUS en secondes depuis Q1 0:00, saisis par les
     * boutons Entrer/Sortir de Stats Live).
     *
     * Cas limites gérés :
     *   - Présence jamais clôturée (restée sur le terrain au buzzer, personne
     *     n'a cliqué « sortir ») → on la clôture à la FIN ESTIMÉE du match :
     *     max(durée réglementaire de la rencontre, plus grande sortie
     *     observée). On ne perd pas les minutes de celles qui finissent
     *     le match sur le terrain — le cas le plus fréquent (le 5 majeur).
     *   - Aucune présence saisie (le bénévole n'a pas utilisé Entrer/Sortir)
     *     → fallback sur l'ancienne approximation par quarts actifs, mais
     *     avec la VRAIE durée de période de la rencontre (4×8 min en jeunes,
     *     pas toujours 10) au lieu du 10 codé en dur.
     *   - Borne physique : jamais plus que durée réglementaire + 2 prolongations.
     *
     * @param PresenceTerrain[] $presences présences déjà filtrées par session
     */
    private function calculerMinutesJouees(
        Joueur $joueur,
        Rencontre $rencontre,
        array $presences,
        ?SessionStatsLive $session,
    ): int {
        if ($presences !== []) {
            $finEstimee = max(
                $rencontre->getDureeTotaleSecondes(),
                $this->presenceTerrainRepository->maxSecondesSortie($rencontre, $session)
            );

            $totalSecondes = 0;
            foreach ($presences as $p) {
                $sortie = $p->getSecondesSortie() ?? max($finEstimee, $p->getSecondesEntree());
                $totalSecondes += max(0, $sortie - $p->getSecondesEntree());
            }

            // Borne physique : durée réglementaire + 2 prolongations de 5 min
            $totalSecondes = min($totalSecondes, $rencontre->getDureeTotaleSecondes() + 2 * 300);

            // intdiv (pas round) : même arrondi que le résumé de match, pour
            // que la fiche joueuse et le résumé affichent le MÊME chiffre.
            return intdiv($totalSecondes, 60);
        }

        // --- Fallback : aucune présence saisie → approximation par quarts ---
        $actions = $this->actionMatchRepository->actionsJoueurSurRencontre($joueur, $rencontre);
        if (empty($actions)) {
            return 0;
        }

        $quartsActifs = [];
        foreach ($actions as $a) {
            $quartsActifs[$a->getQuartTemps()] = true;
        }

        $dureePeriode = $rencontre->getDureePeriodeMinutes();
        $total = 0;
        foreach (array_keys($quartsActifs) as $qt) {
            $total += in_array($qt, [ActionMatch::EXT_1, ActionMatch::EXT_2], true) ? 5 : $dureePeriode;
        }

        // Sanity check : durée réglementaire + 2 prolongations
        return min($total, intdiv($rencontre->getDureeTotaleSecondes(), 60) + 10);
    }

    /**
     * Détecte si une joueuse est titulaire.
     *
     * [V2.4o] Source primaire : PresenceTerrain — titulaire = une présence
     * qui démarre à 0 s (sur le terrain à l'entre-deux). Une remplaçante
     * entrée à la 5e minute du QT1 n'est PLUS comptée titulaire (l'ancienne
     * heuristique « une action dans le QT1 » la comptait).
     *
     * Fallback sans présences : l'heuristique historique (action dans le QT1).
     *
     * @param PresenceTerrain[] $presences présences déjà filtrées par session
     */
    private function detecterTitulaire(Joueur $joueur, Rencontre $rencontre, array $presences): bool
    {
        if ($presences !== []) {
            foreach ($presences as $p) {
                if ($p->getSecondesEntree() === 0) {
                    return true;
                }
            }
            return false;
        }

        return $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(ActionMatch::class, 'a')
            ->where('a.joueur = :joueur')
            ->andWhere('a.rencontre = :rencontre')
            ->andWhere('a.quartTemps = :qt1')
            ->setParameter('joueur', $joueur)
            ->setParameter('rencontre', $rencontre)
            ->setParameter('qt1', ActionMatch::QT_1)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
