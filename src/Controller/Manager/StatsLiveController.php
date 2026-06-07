<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Voter\ClubVoter;
use App\Service\Stats\ActionMatchAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * StatsLiveController — saisie LIVE des stats pendant un match.
 *
 * Façon Easy Stats : stat-man à la table de marque sur tablette paysage,
 * clic joueuse + clic terrain (pour tirs) + clic action.
 *
 * ARCHITECTURE :
 *   - GET  /rencontres/{id}/stats-live          → page Twig (UI saisie)
 *   - POST /rencontres/{id}/stats-live/action   → création ActionMatch (JSON)
 *   - DELETE /rencontres/{id}/stats-live/action/{actionId} → suppression (JSON)
 *   - GET  /rencontres/{id}/stats-live/state    → état complet pour resync (JSON)
 *
 * Toutes les routes POST/DELETE acceptent du JSON et répondent du JSON.
 * Le frontend Twig fait des fetch() AJAX, pas de form POST classique.
 *
 * SÉCURITÉ :
 *   - CLUB_STAFF requis sur toutes les routes
 *   - CSRF token nominatif sur les opérations d'écriture (header X-CSRF-Token)
 *   - Validation type d'action via whitelist ActionMatch::TYPES
 *   - Validation FK joueur appartient bien au club de la rencontre
 *     (sinon un attaquant pourrait créer une action d'une joueuse d'un autre club)
 *
 * SOURCE DE VÉRITÉ :
 *   Chaque ActionMatch créée est la source granulaire. À la fin du match,
 *   le service ActionMatchAggregator peut générer une EvaluationMatch pour
 *   l'export PDF — on ne touche pas à EvaluationMatch en live (cohabitation
 *   propre des 2 modes).
 */
class StatsLiveController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActionMatchRepository $actionMatchRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly ActionMatchAggregator $aggregator,
    ) {}

    /**
     * Page de saisie LIVE — vue Twig.
     *
     *   GET manager.mabb.fr/rencontres/{id}/stats-live
     *
     * Charge en plus :
     *   - Liste des joueuses ACTIVES de l'équipe (sidebar gauche)
     *   - Actions déjà saisies (pour l'historique)
     *   - Comptages agrégés par joueuse (pour le compteur live "X pts" sous chaque joueuse)
     */
    #[Route('/rencontres/{id}/stats-live', name: 'manager_rencontre_stats_live', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // Joueuses actives de l'équipe — affichées dans la sidebar
        $joueuses = $this->joueurRepository->findBy(
            ['equipe' => $rencontre->getEquipe(), 'isActive' => true],
            ['numeroMaillot' => 'ASC', 'nom' => 'ASC']
        );

        // Comptages par joueuse pour le compteur "X pts" sous chaque nom dans la sidebar
        // (calculé côté serveur pour le 1er render, mis à jour en JS après chaque action)
        $comptagesParJoueur = [];
        foreach ($joueuses as $j) {
            $comptagesParJoueur[$j->getId()] = $this->actionMatchRepository->comptageActionsParType($j, $rencontre);
        }

        // Historique des 20 dernières actions du match (pour le footer)
        $historique = $this->em->getRepository(ActionMatch::class)
            ->createQueryBuilder('a')
            ->where('a.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->render('manager/evaluation/stats-live.html.twig', [
            'rencontre'          => $rencontre,
            'joueuses'           => $joueuses,
            'comptages_par_joueur' => $comptagesParJoueur,
            'historique'         => $historique,
            // Constantes exposées pour le JS (types autorisés, quart-temps)
            'types_actions'      => ActionMatch::TYPES,
            'types_avec_position' => ActionMatch::TYPES_AVEC_POSITION,
            'quarts_temps'       => ActionMatch::QUARTS_TEMPS,
        ]);
    }

    /**
     * Création d'une ActionMatch — appelée en AJAX depuis la page.
     *
     *   POST manager.mabb.fr/rencontres/{id}/stats-live/action
     *
     * Body JSON attendu :
     *   {
     *     "joueurId":     12,
     *     "type":         "tir_2pt_int_reussi",
     *     "quartTemps":   "QT1",
     *     "minute":       3,
     *     "secondes":     45,
     *     "positionX":    0.42,     // null si pas un tir
     *     "positionY":    0.15,     // null si pas un tir
     *     "assistJoueurId": null    // facultatif pour passe décisive
     *   }
     *
     * Retour JSON :
     *   {
     *     "success": true,
     *     "actionId": 1234,
     *     "comptages": { "tir_2pt_int_reussi": 3, "rebond_offensif": 2, ... },
     *     "pointsTotal": 8,
     *     "evalEstimee": 12
     *   }
     */
    #[Route('/rencontres/{id}/stats-live/action', name: 'manager_rencontre_stats_live_action_create', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createAction(Request $request, Rencontre $rencontre): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // CSRF nominatif — token transmis via header X-CSRF-Token par le JS
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        // === Validation du joueur (DOIT appartenir au club de la rencontre) ===
        $joueurId = (int) ($data['joueurId'] ?? 0);
        $joueur = $this->joueurRepository->find($joueurId);
        if (!$joueur instanceof Joueur) {
            return $this->jsonError('Joueuse introuvable.', Response::HTTP_NOT_FOUND);
        }
        if ($joueur->getClub()?->getId() !== $rencontre->getClub()?->getId()) {
            // Anti-IDOR : on refuse une action pour une joueuse d'un autre club
            return $this->jsonError('Joueuse hors club.', Response::HTTP_FORBIDDEN);
        }

        // === Validation du type d'action ===
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ActionMatch::TYPES, true)) {
            return $this->jsonError('Type d\'action invalide.', Response::HTTP_BAD_REQUEST);
        }

        // === Validation du quart-temps ===
        $quartTemps = (string) ($data['quartTemps'] ?? ActionMatch::QT_1);
        if (!in_array($quartTemps, ActionMatch::QUARTS_TEMPS, true)) {
            return $this->jsonError('Quart-temps invalide.', Response::HTTP_BAD_REQUEST);
        }

        // === Création de l'action ===
        $action = new ActionMatch();
        $action->setJoueur($joueur);
        $action->setRencontre($rencontre);
        $action->setType($type);
        $action->setQuartTemps($quartTemps);
        $action->setMinute($this->clampInt($data['minute'] ?? 0, 0, 15));
        $action->setSecondes($this->clampInt($data['secondes'] ?? 0, 0, 59));

        // Position X/Y obligatoire pour les tirs, ignorée pour le reste
        if (in_array($type, ActionMatch::TYPES_AVEC_POSITION, true)) {
            $x = isset($data['positionX']) ? (float) $data['positionX'] : null;
            $y = isset($data['positionY']) ? (float) $data['positionY'] : null;
            if ($x === null || $y === null || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                return $this->jsonError('Position du tir manquante ou invalide.', Response::HTTP_BAD_REQUEST);
            }
            $action->setPositionX($x);
            $action->setPositionY($y);
        }

        // Assist (optionnel) : seulement pour les tirs réussis
        $assistJoueurId = (int) ($data['assistJoueurId'] ?? 0);
        if ($assistJoueurId > 0 && str_ends_with($type, '_reussi')) {
            $assistJoueur = $this->joueurRepository->find($assistJoueurId);
            if ($assistJoueur instanceof Joueur
                && $assistJoueur->getClub()?->getId() === $rencontre->getClub()?->getId()
                && $assistJoueur->getId() !== $joueur->getId()) {
                $action->setAssistJoueur($assistJoueur);

                // Auto-création de l'ActionMatch PASSE_DECISIVE pour le passeur
                $passe = new ActionMatch();
                $passe->setJoueur($assistJoueur);
                $passe->setRencontre($rencontre);
                $passe->setType(ActionMatch::TYPE_PASSE_DECISIVE);
                $passe->setQuartTemps($quartTemps);
                $passe->setMinute($action->getMinute());
                $passe->setSecondes($action->getSecondes());
                $this->em->persist($passe);
            }
        }

        $this->em->persist($action);
        $this->em->flush();

        // === Retour : comptages mis à jour pour la joueuse (pour MAJ live du compteur) ===
        $comptages = $this->actionMatchRepository->comptageActionsParType($joueur, $rencontre);

        return new JsonResponse([
            'success'   => true,
            'actionId'  => $action->getId(),
            'joueurId'  => $joueur->getId(),
            'comptages' => $comptages,
            'pointsTotal' => $this->calculerPointsJoueur($comptages),
        ]);
    }

    /**
     * Suppression d'une ActionMatch (annulation depuis l'historique).
     *
     *   DELETE manager.mabb.fr/rencontres/{id}/stats-live/action/{actionId}
     */
    #[Route('/rencontres/{id}/stats-live/action/{actionId}', name: 'manager_rencontre_stats_live_action_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'actionId' => '\d+'])]
    public function deleteAction(Request $request, Rencontre $rencontre, int $actionId): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $action = $this->actionMatchRepository->find($actionId);
        if (!$action instanceof ActionMatch) {
            return $this->jsonError('Action introuvable.', Response::HTTP_NOT_FOUND);
        }

        // Sécurité : l'action doit appartenir à CETTE rencontre (pas une autre)
        if ($action->getRencontre()?->getId() !== $rencontre->getId()) {
            return $this->jsonError('Action hors rencontre.', Response::HTTP_FORBIDDEN);
        }

        $joueurId = $action->getJoueur()?->getId();
        $this->em->remove($action);
        $this->em->flush();

        // Comptages mis à jour pour la joueuse concernée (MAJ du compteur sidebar)
        $comptages = [];
        if ($joueurId !== null) {
            $joueur = $this->joueurRepository->find($joueurId);
            if ($joueur instanceof Joueur) {
                $comptages = $this->actionMatchRepository->comptageActionsParType($joueur, $rencontre);
            }
        }

        return new JsonResponse([
            'success'   => true,
            'joueurId'  => $joueurId,
            'comptages' => $comptages,
            'pointsTotal' => $this->calculerPointsJoueur($comptages),
        ]);
    }

    // ====================================================================
    // HELPERS PRIVÉS
    // ====================================================================

    /**
     * Calcule les points totaux marqués depuis le comptage des actions.
     * Format de $comptages : ['tir_2pt_int_reussi' => 3, 'lancer_reussi' => 2, ...]
     */
    private function calculerPointsJoueur(array $comptages): int
    {
        $total = 0;
        foreach (ActionMatch::TYPES_QUI_MARQUENT as $type => $valeur) {
            $total += ($comptages[$type] ?? 0) * $valeur;
        }
        return $total;
    }

    /**
     * Cast en int et clamp dans [min, max]. Sécurise les inputs JSON.
     */
    private function clampInt(mixed $value, int $min = 0, int $max = 999): int
    {
        $i = (int) $value;
        return max($min, min($max, $i));
    }

    /**
     * Helper : retour JSON d'erreur normalisé.
     */
    private function jsonError(string $message, int $statusCode): JsonResponse
    {
        return new JsonResponse(
            ['success' => false, 'error' => $message],
            $statusCode
        );
    }
}
