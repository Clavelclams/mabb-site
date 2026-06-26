<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\RencontreRepository;
use App\Repository\Sport\SessionStatsLiveRepository;
use App\Security\Voter\ClubVoter;
use App\Security\Tenant\TenantResolver;
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
        private readonly \App\Repository\Sport\PresenceTerrainRepository $presenceTerrainRepository,
        private readonly \App\Service\Stats\SessionStatsLivePromoteur $sessionPromoteur,
        private readonly \App\Repository\Sport\SessionStatsLiveRepository $sessionRepository,
        private readonly TenantResolver $tenantResolver,
        private readonly RencontreRepository $rencontreRepository,
    ) {}

    // =========================================================================
    // INDEX — Page "Stats Live" dédiée dans la navbar
    // =========================================================================

    /**
     * GET manager.mabb.fr/stats-live
     *
     * Vue centrale pour démarrer / reprendre / consulter les stats live
     * de toutes les rencontres du club. Accessible depuis la navbar.
     *
     * Affiche :
     *   - Rencontres d'aujourd'hui en tête (card orange si en cours)
     *   - Liste de toutes les rencontres + statut de la session stats live
     *   - Bouton "Nouvelle rencontre" pour créer sans passer par /rencontres
     *
     * Statuts affichés :
     *   - Aucune session   → bouton "Démarrer"
     *   - EN_COURS         → bouton "Reprendre ⚡"
     *   - COMPLETE         → bouton "Voir" + badge "À valider"
     *   - OFFICIELLE       → badge "Officielle ✓"
     *   - ARCHIVEE         → badge gris "Archivée"
     */
    #[Route('/stats-live', name: 'manager_stats_live_index', methods: ['GET'])]
    public function liste(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Toutes les rencontres du club, plus récentes en premier, avec equipe JOIN
        $rencontres = $this->rencontreRepository->findByClubOrderedDesc($club->getId());

        // Sessions stats live indexées par rencontre ID (évite N+1)
        $sessionsByRencontreId = $this->sessionRepository->findByClubIndexedByRencontre($club->getId());

        // Rencontres d'aujourd'hui (pour les mettre en avant)
        $today = new \DateTimeImmutable('today');
        $rencontresToday = array_filter(
            $rencontres,
            fn(Rencontre $r) => $r->getDate() !== null && $r->getDate()->format('Y-m-d') === $today->format('Y-m-d')
        );

        return $this->render('manager/stats_live/index.html.twig', [
            'rencontres'              => $rencontres,
            'rencontres_today'        => array_values($rencontresToday),
            'sessions_by_rencontre'   => $sessionsByRencontreId,
            'club'                    => $club,
            'is_staff'                => $this->isGranted(ClubVoter::CLUB_STAFF, $club),
        ]);
    }

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

        // V2.1d Étape 2 — Création/reprise automatique d'une session de saisie
        // pour l'user. Si plusieurs bénévoles saisissent en parallèle, chacun
        // a sa propre session. Les actions seront liées à CETTE session.
        $user = $this->getUser();
        $sessionCourante = null;
        if ($user instanceof \App\Entity\Core\User) {
            $sessionCourante = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $user);
        }

        // Joueuses actives de l'équipe — affichées dans la sidebar
        // [V2.2] isTemporaire=false : on ne veut pas les éphémères d'autres rencontres
        $joueusesActives = $this->joueurRepository->findBy(
            ['equipe' => $rencontre->getEquipe(), 'isActive' => true, 'isTemporaire' => false],
            ['numeroMaillot' => 'ASC', 'nom' => 'ASC']
        );

        // [V2.2] Joueuses éphémères créées SPÉCIFIQUEMENT pour CETTE rencontre
        // Chargées séparément et affichées avec badge coloré dans la sidebar
        $joueusesEphemeres = $this->joueurRepository->findBy(
            ['rencontreOrigine' => $rencontre, 'isActive' => true],
            ['equipeEphemere' => 'ASC', 'numeroMaillot' => 'ASC', 'nom' => 'ASC']
        );

        // V2.1f — Filtre les joueuses non convoquées pour ce match
        // (mais on garde la liste complète pour le modal "Effectif")
        // Cast int explicite : Doctrine peut renvoyer le JSON avec des string
        // selon la version BDD, et in_array(strict:true) casse silencieusement.
        $idsNonConvoquees = array_map('intval', $rencontre->getJoueursNonConvoques());

        // Joueuses officielles convoquées
        $joueuses = array_values(array_filter(
            $joueusesActives,
            fn(Joueur $j) => !in_array((int) $j->getId(), $idsNonConvoquees, true)
        ));

        // [V2.2] Les éphémères adverse ne sont pas "convoquées" au sens classique,
        // on les ajoute toutes (pas de filtre non-convoquées sur elles)
        $joueusesEphemeresNotres = array_values(array_filter(
            $joueusesEphemeres,
            fn(Joueur $j) => !$j->isEphemereAdverse()
                          && !in_array((int) $j->getId(), $idsNonConvoquees, true)
        ));
        $joueusesEphemeresAdverses = array_values(array_filter(
            $joueusesEphemeres,
            fn(Joueur $j) => $j->isEphemereAdverse()
        ));

        // Comptages par joueuse — FILTRÉ par session courante en V2.1d.
        // [V2.2] Inclut aussi les joueuses éphémères (notre équipe + adverses)
        $comptagesParJoueur = [];
        $toutesLesJoueuses  = array_merge($joueuses, $joueusesEphemeresNotres, $joueusesEphemeresAdverses);
        foreach ($toutesLesJoueuses as $j) {
            $qb = $this->actionMatchRepository->createQueryBuilder('a')
                ->select('a.type AS type, COUNT(a.id) AS nb')
                ->where('a.joueur = :joueur')
                ->andWhere('a.rencontre = :rencontre')
                ->setParameter('joueur', $j)
                ->setParameter('rencontre', $rencontre)
                ->groupBy('a.type');
            if ($sessionCourante !== null) {
                $qb->andWhere('a.session = :session')->setParameter('session', $sessionCourante);
            }
            $rows = $qb->getQuery()->getResult();
            $comptages = [];
            foreach ($rows as $r) { $comptages[$r['type']] = (int) $r['nb']; }
            $comptagesParJoueur[$j->getId()] = $comptages;
        }

        // Historique des 20 dernières actions de la session courante (footer)
        $historiqueQb = $this->em->getRepository(ActionMatch::class)
            ->createQueryBuilder('a')
            ->where('a.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre);
        if ($sessionCourante !== null) {
            $historiqueQb->andWhere('a.session = :session')->setParameter('session', $sessionCourante);
        }
        // (la suite du builder est définie juste après)
        $historique = $historiqueQb
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        // === V2.1b — IDs des joueuses ACTUELLEMENT sur le terrain ===
        // V2.1d : filtré par session courante — chaque bénévole a son propre
        // état du 5 sur terrain (sinon ils s'entrechoqueraient en formation).
        $presencesQb = $this->em->getRepository(\App\Entity\Sport\PresenceTerrain::class)
            ->createQueryBuilder('p')
            ->where('p.rencontre = :rencontre')
            ->andWhere('p.secondesSortie IS NULL')
            ->setParameter('rencontre', $rencontre);
        if ($sessionCourante !== null) {
            $presencesQb->andWhere('p.session = :session')->setParameter('session', $sessionCourante);
        }
        $idsSurTerrain = array_map(
            fn(\App\Entity\Sport\PresenceTerrain $p) => $p->getJoueur()?->getId(),
            $presencesQb->getQuery()->getResult()
        );

        return $this->render('manager/evaluation/stats-live.html.twig', [
            'rencontre'                   => $rencontre,
            'joueuses'                    => $joueuses,
            'comptages_par_joueur'        => $comptagesParJoueur,
            'historique'                  => $historique,
            // Constantes exposées pour le JS (types autorisés, quart-temps)
            'types_actions'               => ActionMatch::TYPES,
            'types_avec_position'         => ActionMatch::TYPES_AVEC_POSITION,
            'quarts_temps'                => ActionMatch::QUARTS_TEMPS,
            // V2.1b
            'ids_sur_terrain'             => array_filter($idsSurTerrain),
            // V2.1f — liste complète + non convoquées pour le modal effectif
            'joueuses_toutes'             => $joueusesActives,
            'ids_non_convoquees'          => $idsNonConvoquees,
            // V2.1d Étape 2 — session courante du user
            'session_courante'            => $sessionCourante,
            // [V2.2] Joueuses éphémères de CETTE rencontre (notre côté + adversaires)
            'joueuses_ephemeres_notres'   => $joueusesEphemeresNotres,
            'joueuses_ephemeres_adverses' => $joueusesEphemeresAdverses,
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

        // V2.1d — Récupère la session courante du user (pour lier l'action)
        $userConnecte = $this->getUser();
        $sessionCourante = null;
        if ($userConnecte instanceof \App\Entity\Core\User) {
            $sessionCourante = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $userConnecte);
        }

        // === Création de l'action ===
        $action = new ActionMatch();
        $action->setJoueur($joueur);
        $action->setRencontre($rencontre);
        $action->setSession($sessionCourante);
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
                $passe->setSession($sessionCourante); // V2.1d
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
    // V2.1b — Entrée / Sortie sur le terrain
    // ====================================================================

    /**
     * Faire ENTRER une joueuse sur le terrain.
     * Body JSON : { "joueurId": int, "tempsAbsolu": int (secondes écoulées depuis Q1 0:00) }
     */
    #[Route(
        '/rencontres/{id}/stats-live/entrer',
        name: 'manager_rencontre_stats_live_entrer',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function entrerSurTerrain(Request $request, Rencontre $rencontre): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // CSRF nominatif (même pattern que createAction)
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $joueurId = (int) ($data['joueurId'] ?? 0);
        $tempsAbsolu = $this->clampInt($data['tempsAbsolu'] ?? 0, 0, 99999);

        $joueur = $this->joueurRepository->find($joueurId);
        if (!$joueur instanceof Joueur) {
            return $this->jsonError('Joueuse introuvable.', Response::HTTP_NOT_FOUND);
        }
        // Anti-IDOR : la joueuse doit être dans l'équipe de la rencontre
        if ($joueur->getEquipe()?->getId() !== $rencontre->getEquipe()?->getId()) {
            return $this->jsonError('Joueuse hors équipe.', Response::HTTP_FORBIDDEN);
        }

        // Idempotence : si elle est déjà sur le terrain, on renvoie OK sans rien créer
        $deja = $this->presenceTerrainRepository->findEnCoursForJoueur($joueur, $rencontre);
        if ($deja !== null) {
            return new JsonResponse([
                'success'    => true,
                'presenceId' => $deja->getId(),
                'joueurId'   => $joueur->getId(),
                'note'       => 'Déjà sur le terrain.',
            ]);
        }

        // V2.1d — Lie la présence à la session courante de l'user
        $userConnecte = $this->getUser();
        $sessionCourante = null;
        if ($userConnecte instanceof \App\Entity\Core\User) {
            $sessionCourante = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $userConnecte);
        }

        $presence = new \App\Entity\Sport\PresenceTerrain();
        $presence->setJoueur($joueur);
        $presence->setRencontre($rencontre);
        $presence->setSession($sessionCourante);
        $presence->setSecondesEntree($tempsAbsolu);
        $this->em->persist($presence);
        $this->em->flush();

        return new JsonResponse([
            'success'    => true,
            'presenceId' => $presence->getId(),
            'joueurId'   => $joueur->getId(),
        ]);
    }

    /**
     * Faire SORTIR une joueuse du terrain.
     * Body JSON : { "joueurId": int, "tempsAbsolu": int }
     */
    #[Route(
        '/rencontres/{id}/stats-live/sortir',
        name: 'manager_rencontre_stats_live_sortir',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function sortirDuTerrain(Request $request, Rencontre $rencontre): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $joueurId = (int) ($data['joueurId'] ?? 0);
        $tempsAbsolu = $this->clampInt($data['tempsAbsolu'] ?? 0, 0, 99999);

        $joueur = $this->joueurRepository->find($joueurId);
        if (!$joueur instanceof Joueur) {
            return $this->jsonError('Joueuse introuvable.', Response::HTTP_NOT_FOUND);
        }

        $presence = $this->presenceTerrainRepository->findEnCoursForJoueur($joueur, $rencontre);
        if ($presence === null) {
            return $this->jsonError('Cette joueuse n\'est pas sur le terrain.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $presence->setSecondesSortie($tempsAbsolu);
            $this->em->flush();
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success'   => true,
            'joueurId'  => $joueur->getId(),
            'dureeSec'  => $presence->getDureeSecondes() ?? 0,
        ]);
    }

    // ====================================================================
    // V2.1e — Score adverse en live
    // ====================================================================

    /**
     * Ajoute (ou retire) des points au score adverse de la rencontre.
     * Body JSON : { "delta": int } (peut être négatif pour corriger)
     *
     * Validation : delta dans [-99, +99] pour éviter les inputs sauvages.
     * Le score adverse final est clampé dans [0, 300].
     */
    #[Route(
        '/rencontres/{id}/stats-live/score-adverse',
        name: 'manager_rencontre_stats_live_score_adverse',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function updateScoreAdverse(Request $request, Rencontre $rencontre): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $delta = (int) ($data['delta'] ?? 0);
        if ($delta < -99 || $delta > 99) {
            return $this->jsonError('Delta hors borne.', Response::HTTP_BAD_REQUEST);
        }

        $actuel = $rencontre->getScoreAdverse() ?? 0;
        $nouveau = max(0, min(300, $actuel + $delta));
        $rencontre->setScoreAdverse($nouveau);
        $this->em->flush();

        return new JsonResponse([
            'success'      => true,
            'scoreAdverse' => $nouveau,
        ]);
    }

    // ====================================================================
    // V2.1f — Effectif du match (qui joue / ne joue pas)
    // ====================================================================

    /**
     * Met à jour la liste des joueuses NON convoquées pour ce match.
     * Body JSON : { "joueursNonConvoques": int[] }
     *
     * Validation : tous les IDs doivent appartenir à l'équipe de la rencontre.
     */
    #[Route(
        '/rencontres/{id}/stats-live/effectif',
        name: 'manager_rencontre_stats_live_effectif',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function updateEffectif(Request $request, Rencontre $rencontre): JsonResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('stats_live_' . $rencontre->getId(), $token)) {
            return $this->jsonError('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->jsonError('JSON invalide.', Response::HTTP_BAD_REQUEST);
        }

        $idsNonConvoquees = $data['joueursNonConvoques'] ?? [];
        if (!is_array($idsNonConvoquees)) {
            return $this->jsonError('joueursNonConvoques doit être un tableau.', Response::HTTP_BAD_REQUEST);
        }

        // Validation : IDs ∈ effectif équipe
        $idsEquipe = array_map(
            fn(Joueur $j) => $j->getId(),
            $this->joueurRepository->findBy(['equipe' => $rencontre->getEquipe(), 'isActive' => true])
        );
        $idsValides = array_intersect(array_map('intval', $idsNonConvoquees), $idsEquipe);

        $rencontre->setJoueursNonConvoques($idsValides);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'joueursNonConvoques' => $rencontre->getJoueursNonConvoques(),
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
