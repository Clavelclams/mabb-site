<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\PresenceTerrain;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\PresenceTerrainRepository;
use App\Repository\Sport\RencontreRepository;
use App\Service\ConvocationManager;
use App\Service\Stats\SessionStatsLivePromoteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-6 04/08/2026] La SAISIE Stats Live depuis l'app — le miroir Bearer du web.
 *
 * POURQUOI CE CONTRÔLEUR EXISTE : la saisie web (StatsLiveController) vit sur
 * le host manager avec une session navigateur et un jeton CSRF. Une app
 * mobile n'a ni l'un ni l'autre. On expose donc les MÊMES opérations sur
 * /api/club/*, authentifiées par jeton Bearer.
 *
 * CE QUI EST PARTAGÉ ET CE QUI EST RÉPÉTÉ, dit honnêtement :
 *   - Les SESSIONS passent par SessionStatsLivePromoteur (le même service
 *     que le web) : chaque saisisseur a la sienne, la promotion reste le
 *     geste de validation (VC-5).
 *   - Les petites validations d'entrée (type d'action dans la whitelist,
 *     bornes minute/seconde, appartenance de la joueuse) sont RÉÉCRITES ici,
 *     comme au web. Elles tiennent en quelques lignes et s'appuient déjà sur
 *     les constantes de l'entité (la vraie source de vérité) : les extraire
 *     dans un service aurait plus de coût que de valeur aujourd'hui. Si un
 *     TROISIÈME client apparaît, on extrait.
 *
 * MODÈLE DE SAISIE (identique au web) :
 *   une ActionMatch par événement, rattachée à la session du saisisseur ;
 *   PresenceTerrain pour les entrées/sorties (temps absolu en secondes) ;
 *   le score adverse sur la rencontre. Rien de nouveau en base.
 */
final class ApiClubSaisieController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RencontreRepository $rencontreRepository,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly ActionMatchRepository $actionMatchRepository,
        private readonly PresenceTerrainRepository $presenceTerrainRepository,
        private readonly SessionStatsLivePromoteur $sessionPromoteur,
        private readonly ConvocationManager $convocations,
    ) {}

    /**
     * GET /api/club/rencontres/{id}/saisie — TOUT l'état pour ouvrir l'écran.
     *
     * Renvoie : la rencontre (format des périodes compris), l'effectif avec
     * points et présence terrain, le score adverse, la session courante du
     * saisisseur et ses dernières actions (pour l'annulation).
     *
     * L'app peut donc s'ouvrir, se fermer, revenir : l'état est au serveur.
     */
    #[Route('/api/club/rencontres/{id}/saisie', name: 'api_club_saisie_etat', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function etat(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);
        $session = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $this->utilisateur());

        // L'effectif proposé : les convoquées si une convocation existe,
        // sinon toute l'équipe. Le bénévole de table n'a pas à se demander
        // qui joue : la liste est déjà la bonne.
        $effectif = $this->convocations->effectifConvocable($rencontre);
        $convoquees = $this->convocations->convocationsExistantes($rencontre);
        if ($convoquees !== []) {
            $effectif = array_intersect_key($effectif, $convoquees);
        }

        // Comptages par joueuse, bornés à MA session (comme le web) : deux
        // saisisseurs en parallèle ne se polluent pas.
        $comptagesParJoueur = [];
        $rows = $this->actionMatchRepository->createQueryBuilder('a')
            ->select('IDENTITY(a.joueur) AS jid, a.type AS type, COUNT(a.id) AS nb')
            ->where('a.rencontre = :r')->setParameter('r', $rencontre)
            ->andWhere('a.session = :s')->setParameter('s', $session)
            ->groupBy('jid, a.type')
            ->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $comptagesParJoueur[(int) $row['jid']][$row['type']] = (int) $row['nb'];
        }

        // Qui est sur le terrain (présences ouvertes de MA session).
        $surTerrain = [];
        foreach ($this->presenceTerrainRepository->findOuvertes($rencontre, $session) as $p) {
            $jid = $p->getJoueur()?->getId();
            if ($jid !== null) {
                $surTerrain[] = (int) $jid;
            }
        }

        // Les 15 dernières actions de MA session : l'historique d'annulation.
        $dernieres = $this->actionMatchRepository->createQueryBuilder('a')
            ->select('a', 'j')
            ->join('a.joueur', 'j')
            ->where('a.rencontre = :r')->setParameter('r', $rencontre)
            ->andWhere('a.session = :s')->setParameter('s', $session)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(15)
            ->getQuery()->getResult();

        $joueuses = [];
        foreach ($effectif as $jid => $j) {
            $points = 0;
            foreach (ActionMatch::TYPES_QUI_MARQUENT as $type => $valeur) {
                $points += ($comptagesParJoueur[$jid][$type] ?? 0) * $valeur;
            }
            $joueuses[] = [
                'id'            => $jid,
                'prenom'        => $j->getPrenom(),
                'nom'           => $j->getNom(),
                'numeroMaillot' => $j->getNumeroMaillot(),
                'points'        => $points,
                'fautes'        => $comptagesParJoueur[$jid][ActionMatch::TYPE_FAUTE_COMMISE] ?? 0,
                'surTerrain'    => in_array($jid, $surTerrain, true),
            ];
        }

        $scoreNous = 0;
        foreach ($joueuses as $j) {
            $scoreNous += $j['points'];
        }

        return new JsonResponse([
            'rencontre' => [
                'id'                  => $rencontre->getId(),
                'adversaire'          => $rencontre->getAdversaire(),
                'domicile'            => $rencontre->isDomicile(),
                'nbPeriodes'          => $rencontre->getNbPeriodes(),
                'dureePeriodeMinutes' => $rencontre->getDureePeriodeMinutes(),
                'scoreAdverse'        => $rencontre->getScoreAdverse() ?? 0,
            ],
            'session' => [
                'id'     => $session->getId(),
                'nom'    => $session->getNom(),
                'statut' => $session->getStatut(),
            ],
            'joueuses'   => $joueuses,
            'scoreNous'  => $scoreNous,
            'dernieresActions' => array_map(static fn(ActionMatch $a) => [
                'id'      => $a->getId(),
                'type'    => $a->getType(),
                'joueuse' => trim(($a->getJoueur()?->getPrenom() ?? '') . ' ' . ($a->getJoueur()?->getNom() ?? '')),
                'quartTemps' => $a->getQuartTemps(),
            ], $dernieres),
        ]);
    }

    /**
     * POST /api/club/rencontres/{id}/saisie/action — une action de jeu.
     *
     * Corps : {joueurId, type, quartTemps, minute, secondes, positionX?, positionY?}
     * Retour : {actionId, points, fautes, scoreNous} — de quoi mettre à jour
     * l'écran sans re-télécharger tout l'état.
     */
    #[Route('/api/club/rencontres/{id}/saisie/action', name: 'api_club_saisie_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function action(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);
        $session = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $this->utilisateur());

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->erreur('JSON invalide.', 400);
        }

        // Même règle anti-IDOR que le web : la joueuse doit appartenir au
        // club de la rencontre. Sinon un client bricolé écrirait des stats
        // sur la fiche d'une joueuse d'un autre club.
        $joueur = $this->joueurRepository->find((int) ($data['joueurId'] ?? 0));
        if ($joueur === null || $joueur->getClub()?->getId() !== $rencontre->getClub()?->getId()) {
            return $this->erreur('Joueuse introuvable.', 404);
        }

        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ActionMatch::TYPES, true)) {
            return $this->erreur('Type d\'action invalide.', 400);
        }
        $quartTemps = (string) ($data['quartTemps'] ?? ActionMatch::QT_1);
        if (!in_array($quartTemps, ActionMatch::QUARTS_TEMPS, true)) {
            return $this->erreur('Quart-temps invalide.', 400);
        }

        $action = new ActionMatch();
        $action->setJoueur($joueur);
        $action->setRencontre($rencontre);
        $action->setSession($session);
        $action->setType($type);
        $action->setQuartTemps($quartTemps);
        $action->setMinute(max(0, min(15, (int) ($data['minute'] ?? 0))));
        $action->setSecondes(max(0, min(59, (int) ($data['secondes'] ?? 0))));

        // Position facultative (le shot chart V1 de l'app viendra après —
        // le serveur l'accepte déjà, comme au web).
        $x = isset($data['positionX']) ? (float) $data['positionX'] : null;
        $y = isset($data['positionY']) ? (float) $data['positionY'] : null;
        if ($x !== null && $y !== null && $x >= 0 && $x <= 1 && $y >= 0 && $y <= 1) {
            $action->setPositionX($x);
            $action->setPositionY($y);
        }

        $this->em->persist($action);
        $this->em->flush();

        return new JsonResponse([
            'actionId' => $action->getId(),
            'points'   => $this->pointsJoueuse($joueur->getId(), $rencontre, $session),
            'scoreNous' => $this->scoreSession($rencontre, $session),
        ]);
    }

    /**
     * DELETE /api/club/saisie/action/{actionId} — l'annulation.
     *
     * On ne peut annuler que les actions de SA PROPRE session : un
     * saisisseur ne défait jamais le travail d'un autre.
     */
    #[Route('/api/club/saisie/action/{actionId}', name: 'api_club_saisie_action_suppr', methods: ['DELETE'], requirements: ['actionId' => '\d+'])]
    public function supprimerAction(Request $request, int $actionId): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $action = $this->actionMatchRepository->find($actionId);
        $rencontre = $action?->getRencontre();

        if ($action === null || $rencontre === null
            || $rencontre->getClub()?->getId() !== $club->getId()
            || $action->getSession()?->getCreatedBy()?->getId() !== $this->utilisateur()->getId()) {
            return $this->erreur('Action introuvable.', 404);
        }

        $session = $action->getSession();
        $this->em->remove($action);
        $this->em->flush();

        return new JsonResponse([
            'succes'    => true,
            'scoreNous' => $session !== null ? $this->scoreSession($rencontre, $session) : 0,
        ]);
    }

    /**
     * POST /api/club/rencontres/{id}/saisie/terrain — entrée ou sortie.
     *
     * Corps : {joueurId, sens: "entree"|"sortie", tempsAbsolu} (secondes
     * écoulées depuis le début du match, fournies par le chrono de l'app).
     * C'est CE flux qui donne les minutes jouées réelles des joueuses.
     */
    #[Route('/api/club/rencontres/{id}/saisie/terrain', name: 'api_club_saisie_terrain', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function terrain(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);
        $session = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $this->utilisateur());

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->erreur('JSON invalide.', 400);
        }

        $joueur = $this->joueurRepository->find((int) ($data['joueurId'] ?? 0));
        if ($joueur === null || $joueur->getClub()?->getId() !== $rencontre->getClub()?->getId()) {
            return $this->erreur('Joueuse introuvable.', 404);
        }

        $tempsAbsolu = max(0, min(99999, (int) ($data['tempsAbsolu'] ?? 0)));
        $sens = (string) ($data['sens'] ?? '');

        // La présence ouverte de cette joueuse dans MA session, s'il y en a une.
        $ouverte = null;
        foreach ($this->presenceTerrainRepository->findOuvertes($rencontre, $session) as $p) {
            if ($p->getJoueur()?->getId() === $joueur->getId()) {
                $ouverte = $p;
                break;
            }
        }

        if ($sens === 'entree') {
            // Idempotent : déjà sur le terrain → OK sans doublon.
            if ($ouverte === null) {
                $presence = new PresenceTerrain();
                $presence->setJoueur($joueur);
                $presence->setRencontre($rencontre);
                $presence->setSession($session);
                $presence->setSecondesEntree($tempsAbsolu);
                $this->em->persist($presence);
                $this->em->flush();
            }
            return new JsonResponse(['succes' => true, 'surTerrain' => true]);
        }

        if ($sens === 'sortie') {
            if ($ouverte === null) {
                return $this->erreur('Cette joueuse n\'est pas sur le terrain.', 409);
            }
            try {
                $ouverte->setSecondesSortie($tempsAbsolu);
            } catch (\InvalidArgumentException $e) {
                return $this->erreur($e->getMessage(), 400);
            }
            $this->em->flush();
            return new JsonResponse(['succes' => true, 'surTerrain' => false]);
        }

        return $this->erreur('Sens invalide : "entree" ou "sortie".', 400);
    }

    /**
     * POST /api/club/rencontres/{id}/saisie/score-adverse — {delta}.
     * Le score adverse est global à la rencontre (pas par session), comme au web.
     */
    #[Route('/api/club/rencontres/{id}/saisie/score-adverse', name: 'api_club_saisie_score_adverse', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function scoreAdverse(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);

        $data = json_decode($request->getContent(), true);
        $delta = is_array($data) ? (int) ($data['delta'] ?? 0) : 0;
        if ($delta < -99 || $delta > 99) {
            return $this->erreur('Delta hors borne.', 400);
        }

        $nouveau = max(0, min(300, ($rencontre->getScoreAdverse() ?? 0) + $delta));
        $rencontre->setScoreAdverse($nouveau);
        $this->em->flush();

        return new JsonResponse(['succes' => true, 'scoreAdverse' => $nouveau]);
    }

    /**
     * POST /api/club/rencontres/{id}/saisie/terminer — fin de la saisie.
     *
     * Marque MA session COMPLETE. Elle apparaît alors dans « Stats à
     * valider » (VC-5) : la boucle saisie → validation → stats joueuses est
     * fermée, entièrement depuis un téléphone.
     */
    #[Route('/api/club/rencontres/{id}/saisie/terminer', name: 'api_club_saisie_terminer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function terminer(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);
        $session = $this->sessionPromoteur->obtenirOuCreerSessionPourUser($rencontre, $this->utilisateur());

        try {
            $this->sessionPromoteur->marquerComplete($session);
        } catch (\RuntimeException $e) {
            return $this->erreur($e->getMessage(), 409);
        }

        return new JsonResponse([
            'succes'  => true,
            'message' => 'Saisie terminée. Pensez à la valider dans « Stats à valider » pour publier les stats.',
        ]);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /** Même double contrôle que la convocation : club + équipe encadrée. */
    private function rencontreAutorisee(Request $request, int $id): Rencontre
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $rencontre = $this->rencontreRepository->find($id);
        if (!$rencontre instanceof Rencontre
            || $rencontre->getClub()?->getId() !== $club->getId()) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }

        $equipe = $rencontre->getEquipe();
        $user = $this->utilisateur();
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(\App\Entity\Core\UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if (!$estDirigeant && ($equipe === null || !$this->coachEquipeRepository->estCoachDeEquipe($user, $equipe))) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }

        return $rencontre;
    }

    private function pointsJoueuse(?int $joueurId, Rencontre $rencontre, \App\Entity\Sport\SessionStatsLive $session): int
    {
        if ($joueurId === null) {
            return 0;
        }
        $rows = $this->actionMatchRepository->createQueryBuilder('a')
            ->select('a.type AS type, COUNT(a.id) AS nb')
            ->where('a.rencontre = :r')->setParameter('r', $rencontre)
            ->andWhere('a.session = :s')->setParameter('s', $session)
            ->andWhere('a.joueur = :j')->setParameter('j', $joueurId)
            ->groupBy('a.type')
            ->getQuery()->getArrayResult();
        $points = 0;
        foreach ($rows as $row) {
            $points += (ActionMatch::TYPES_QUI_MARQUENT[$row['type']] ?? 0) * (int) $row['nb'];
        }
        return $points;
    }

    private function scoreSession(Rencontre $rencontre, \App\Entity\Sport\SessionStatsLive $session): int
    {
        $rows = $this->actionMatchRepository->createQueryBuilder('a')
            ->select('a.type AS type, COUNT(a.id) AS nb')
            ->where('a.rencontre = :r')->setParameter('r', $rencontre)
            ->andWhere('a.session = :s')->setParameter('s', $session)
            ->groupBy('a.type')
            ->getQuery()->getArrayResult();
        $score = 0;
        foreach ($rows as $row) {
            $score += (ActionMatch::TYPES_QUI_MARQUENT[$row['type']] ?? 0) * (int) $row['nb'];
        }
        return $score;
    }
}
