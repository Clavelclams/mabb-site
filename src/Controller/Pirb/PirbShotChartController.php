<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\SeanceTir;
use App\Entity\Sport\TirFfbb;
use App\Entity\Sport\ZoneTir;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\SeanceTirRepository;
use App\Repository\Sport\TirFfbbRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shot chart V2.3 — espace joueuse (PIRB).
 *
 * Ce controller gère 3 routes :
 *
 * ① GET  /shot-chart
 *    Page principale : shot map + filtres + courbes de progression.
 *    Affiche toutes les séances validées (et les non-validées avec badge "en attente").
 *
 * ② POST /shot-chart/sauvegarder
 *    Reçoit les zones dessinées depuis le JS du terrain cliquable.
 *    Payload JSON :
 *      {
 *        "date":   "2026-06-25",      // ISO date
 *        "source": "ENTRAINEMENT",    // ou "MATCH"
 *        "notes":  "...",             // optionnel
 *        "zones":  [
 *          { "x": 0.34, "y": 0.72, "typeTir": "3pt", "reussis": 9, "tentatives": 16 },
 *          ...
 *        ]
 *      }
 *    Retourne JSON {success: true, seanceId: 42, validatedByCoach: false}
 *    → validatedByCoach est toujours false ici (coach valide via Manager)
 *
 * ③ DELETE /shot-chart/{id}/supprimer
 *    Supprime une séance non encore validée.
 *    Une séance validée ne peut pas être supprimée par la joueuse.
 *
 * Sécurité multi-tenant :
 *   - La joueuse ne peut voir et modifier que ses propres séances.
 *   - CSRF sur tous les POST/DELETE.
 */
class PirbShotChartController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository    $joueurRepo,
        private readonly SeanceTirRepository $seanceTirRepo,
        private readonly TirFfbbRepository   $tirFfbbRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    // =========================================================================
    // ① Page principale shot chart
    // =========================================================================

    /**
     * GET /shot-chart
     *
     * Paramètres GET (filtres) :
     *   source : ENTRAINEMENT | MATCH | '' (tous)
     *   from   : date ISO YYYY-MM-DD
     *   to     : date ISO YYYY-MM-DD
     *   type   : 2pt_int | 2pt_ext | 3pt | lancer | '' (tous)
     */
    #[Route('/shot-chart', name: 'pirb_shot_chart', methods: ['GET'])]
    public function index(Request $request, \App\Service\SaisonService $saisonService): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée à ton compte.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // --- Filtres GET ---
        $source = $request->query->get('source', '');
        $from   = $request->query->get('from', '');
        $to     = $request->query->get('to', '');

        $fromDate = $from !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $from) ?: null : null;
        $toDate   = $to   !== '' ? \DateTimeImmutable::createFromFormat('Y-m-d', $to)   ?: null : null;

        // --- Séances pour la shot map (validées uniquement) ---
        $seancesValidees = $this->seanceTirRepo->findForShotMap(
            joueur: $joueur,
            source: $source !== '' ? $source : null,
            from:   $fromDate,
            to:     $toDate,
            validatedOnly: true
        );

        // --- Séances en attente de validation (toujours visibles pour info) ---
        $seancesEnAttente = $this->seanceTirRepo->findForShotMap(
            joueur: $joueur,
            source: SeanceTir::SOURCE_ENTRAINEMENT,
            from:   null,
            to:     null,
            validatedOnly: false  // Récupère TOUT — on filtre ci-dessous
        );
        // Garder seulement les non-validées
        $seancesEnAttente = array_filter(
            $seancesEnAttente,
            fn(SeanceTir $s) => !$s->isValidatedByCoach()
        );

        // --- Données pour courbes de progression ---
        $progressionData = $this->seanceTirRepo->findProgressionData(
            joueur: $joueur,
            typeTir: null, // tous types
            from:   $fromDate,
            to:     $toDate
        );

        // --- Toutes les zones pour la shot map (JSON pour JS) ---
        $zonesSeances = $this->buildZonesJson($seancesValidees);

        // --- TirFfbb : tirs des matchs importés depuis FFBB ---
        // [V2.4i 10/07/2026] FILTRÉS PAR SAISON ACTIVE (bug : en 2026-2027 on
        // voyait encore les tirs des matchs 2025-26). Bornes = fenêtre de la
        // saison active (bascule 1er juillet, même règle que SaisonService),
        // sauf si l'utilisatrice a posé ses propres filtres de dates (from/to
        // explicites → ils priment). Les SÉANCES DE SHOOT, elles, restent
        // TOUTES visibles (demande explicite : « juste laisser les séances »).
        $saisonActive = $saisonService->getSaisonActive();
        $anneeDebut   = (int) explode('-', $saisonActive)[0];
        $debutSaison  = new \DateTimeImmutable($anneeDebut . '-07-01 00:00:00');
        $finSaison    = $debutSaison->modify('+1 year');

        $tirsFfbb  = $this->tirFfbbRepo->findForJoueur($joueur);
        $zonesFfbb = [];
        if ($source === '' || $source === SeanceTir::SOURCE_MATCH) {
            $zonesFfbb = $this->buildZonesJsonFromTirFfbb(
                $tirsFfbb,
                $fromDate ?? $debutSaison,
                $toDate   ?? $finSaison,
            );
        }

        // [V2.4 05/07/2026] Les tirs FFBB ne sont PLUS fusionnés dans la shot
        // map d'entraînement : ils ont leur propre terrain "FFBB officiel"
        // (proportions identiques au doc e-Marque → placement précis).
        // zones_json = séances uniquement ; zones_ffbb_json = matchs FFBB.
        $zonesJson = $zonesSeances;

        // --- Stats globales (badges résumé) — inclut les tirs FFBB ---
        $statsGlobales = $this->buildStatsGlobales($seancesValidees, $zonesFfbb);

        // --- Liste des matchs FFBB pour le sélecteur ---
        // [V2.4b 06/07/2026] Construite depuis $zonesFfbb (la MÊME liste que
        // les points affichés sur le terrain) et non plus depuis $tirsFfbb :
        // garantit par construction que le chiffre 🏀 de chaque mini-card
        // == le nombre de points bleus visibles pour ce match.
        $matchesFfbb = $this->buildMatchesList($zonesFfbb);

        return $this->render('pirb/shot_chart/index.html.twig', [
            'joueur'               => $joueur,
            'seances_validees'     => $seancesValidees,
            'seances_en_attente'   => array_values($seancesEnAttente),
            'zones_json'           => json_encode($zonesJson),
            'zones_ffbb_json'      => json_encode($zonesFfbb),
            'progression_data'     => json_encode($progressionData),
            'stats_globales'       => $statsGlobales,
            'matches_ffbb'         => $matchesFfbb,
            // FFBB : données partielles (tirs réussis uniquement → pas de % fiable)
            'has_seances_validees' => count($seancesValidees) > 0,
            'nb_tirs_ffbb'         => count($zonesFfbb),
            // Filtres actuels (pour pré-remplir les inputs)
            'filtre_source'        => $source,
            'filtre_from'          => $from,
            'filtre_to'            => $to,
        ]);
    }

    // =========================================================================
    // ② Sauvegarder une séance depuis le terrain cliquable
    // =========================================================================

    /**
     * POST /shot-chart/sauvegarder
     *
     * Body JSON attendu :
     * {
     *   "date":   "2026-06-25",
     *   "source": "ENTRAINEMENT",
     *   "notes":  "Travail côté gauche",
     *   "zones":  [
     *     { "x": 0.34, "y": 0.72, "typeTir": "3pt",     "reussis": 9,  "tentatives": 16 },
     *     { "x": 0.15, "y": 0.20, "typeTir": "2pt_int", "reussis": 12, "tentatives": 15 }
     *   ]
     * }
     *
     * Réponse 201 :
     * { "success": true, "seanceId": 42, "validatedByCoach": false, "nbZones": 2 }
     *
     * Réponse 422 :
     * { "error": "Message d'erreur" }
     */
    #[Route('/shot-chart/sauvegarder', name: 'pirb_shot_chart_sauvegarder', methods: ['POST'])]
    public function sauvegarder(Request $request): JsonResponse
    {
        // --- CSRF JSON ---
        $csrfToken = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('shot_chart_nouvelle', $csrfToken)) {
            return new JsonResponse(['error' => 'Token de sécurité invalide.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            return new JsonResponse(['error' => 'Aucune fiche joueuse.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        // --- Validation minimale ---
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Payload JSON invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dateStr = $data['date'] ?? '';
        $source  = $data['source'] ?? SeanceTir::SOURCE_ENTRAINEMENT;
        $zones   = $data['zones'] ?? [];
        $notes   = $data['notes'] ?? null;

        // Valider la date
        $dateSeance = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($dateSeance === false) {
            return new JsonResponse(['error' => 'Date invalide. Format attendu : YYYY-MM-DD.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        // createFromFormat('Y-m-d', ...) hérite de l'heure courante — on la remet à minuit
        // pour éviter que "aujourd'hui à 17h" > "aujourd'hui 00:00" soit vrai.
        $dateSeance = $dateSeance->setTime(0, 0, 0);

        // Pas dans le futur (comparaison date pure, minuit vs minuit)
        if ($dateSeance > new \DateTimeImmutable('today midnight')) {
            return new JsonResponse(['error' => 'La date ne peut pas être dans le futur.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Valider la source
        if (!in_array($source, SeanceTir::SOURCES, true)) {
            $source = SeanceTir::SOURCE_ENTRAINEMENT;
        }

        // Au moins une zone
        if (empty($zones)) {
            return new JsonResponse(['error' => 'Aucune zone de tir fournie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Max 50 zones par séance (sanity check)
        if (count($zones) > 50) {
            return new JsonResponse(['error' => 'Maximum 50 zones par séance.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // --- Créer la SeanceTir ---
        $seance = new SeanceTir();
        $seance->setJoueur($joueur);
        $seance->setClub($joueur->getClub());
        $seance->setSource($source);
        $seance->setDateSeance($dateSeance);
        $seance->setNotes($notes !== '' ? $notes : null);
        // ENTRAINEMENT → validatedByCoach=false (coach doit valider)
        // MATCH ne passe pas par ici (généré depuis la session stats live)

        $nbZonesValides = 0;
        foreach ($zones as $zoneData) {
            if (!is_array($zoneData)) continue;

            $typeTir    = $zoneData['typeTir']    ?? SeanceTir::TYPE_2PT_EXT;
            $tentatives = (int) ($zoneData['tentatives'] ?? 1);
            $reussis    = (int) ($zoneData['reussis']    ?? 0);
            $x          = (float) ($zoneData['x'] ?? 0.5);
            $y          = (float) ($zoneData['y'] ?? 0.5);

            // Validation données zone
            if (!in_array($typeTir, SeanceTir::TYPES_TIR, true)) {
                $typeTir = SeanceTir::TYPE_2PT_EXT;
            }
            if ($tentatives < 1)  $tentatives = 1;
            if ($tentatives > 999) $tentatives = 999;
            if ($reussis < 0)     $reussis = 0;
            if ($reussis > $tentatives) $reussis = $tentatives;

            $zone = new ZoneTir();
            $zone->setTypeTir($typeTir);
            $zone->setTentatives($tentatives);
            $zone->setReussis($reussis);
            $zone->setPositionX($x);
            $zone->setPositionY($y);

            $seance->addZone($zone);
            $nbZonesValides++;
        }

        if ($nbZonesValides === 0) {
            return new JsonResponse(['error' => 'Aucune zone valide fournie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->persist($seance);
        $this->em->flush();

        return new JsonResponse([
            'success'          => true,
            'seanceId'         => $seance->getId(),
            'validatedByCoach' => $seance->isValidatedByCoach(),
            'nbZones'          => $nbZonesValides,
            'date'             => $dateSeance->format('d/m/Y'),
        ], Response::HTTP_CREATED);
    }

    // =========================================================================
    // ③ Supprimer une séance (uniquement si non validée)
    // =========================================================================

    /**
     * DELETE /shot-chart/{id}/supprimer
     * Header : X-CSRF-Token avec token 'shot_chart_supprimer_{id}'
     */
    #[Route('/shot-chart/{id}/supprimer', name: 'pirb_shot_chart_supprimer', methods: ['DELETE'])]
    public function supprimer(SeanceTir $seance, Request $request): JsonResponse
    {
        $csrfToken = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('shot_chart_supprimer_' . $seance->getId(), $csrfToken)) {
            return new JsonResponse(['error' => 'Token invalide.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null || $seance->getJoueur()?->getId() !== $joueur->getId()) {
            return new JsonResponse(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        if ($seance->isValidatedByCoach()) {
            return new JsonResponse(['error' => 'Impossible de supprimer une séance validée par le coach.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($seance);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    // =========================================================================
    // Helpers privés
    // =========================================================================

    /**
     * Transforme les séances en tableau JSON plat pour le rendu JS du terrain.
     *
     * @param SeanceTir[] $seances
     * @return array<int, array{seanceId: int, date: string, source: string, x: float, y: float, typeTir: string, reussis: int, tentatives: int, pct: float|null, couleur: string}>
     */
    private function buildZonesJson(array $seances): array
    {
        $result = [];
        foreach ($seances as $seance) {
            foreach ($seance->getZones() as $zone) {
                $result[] = [
                    'seanceId'   => $seance->getId(),
                    'date'       => $seance->getDateSeance()?->format('Y-m-d') ?? '',
                    'source'     => $seance->getSource(),
                    'x'          => $zone->getPositionX(),
                    'y'          => $zone->getPositionY(),
                    'typeTir'    => $zone->getTypeTir(),
                    'reussis'    => $zone->getReussis(),
                    'tentatives' => $zone->getTentatives(),
                    'pct'        => $zone->getPourcentage(),
                    'couleur'    => $zone->getCouleurHsl(),
                    'label'      => $zone->getRatio(),
                ];
            }
        }
        return $result;
    }

    /**
     * Convertit les TirFfbb en zones JSON pour la shot map.
     *
     * Seuls les tirs avec positionX/Y non null sont placés sur la map
     * (les tirs sans coordonnées viennent d'un PDF sans shot chart ou d'une
     * extraction ratée — on les ignore visuellement mais ils comptent dans les stats
     * → ici on ne les inclut PAS pour ne pas biaiser la carte).
     *
     * Coordonnées : positionX/Y sont stockées en 0-100 (% du terrain).
     * La shot map attend des valeurs 0.0-1.0 → division par 100.
     *
     * Couleur : même logique que ZoneTir::getCouleurHsl() → hsl(hue, 80%, 45%)
     * avec hue = pct * 1.2 (0% = rouge, 100% = vert).
     *
     * @param TirFfbb[] $tirs
     * @param \DateTimeImmutable|null $fromDate
     * @param \DateTimeImmutable|null $toDate
     * @return array
     */
    private function buildZonesJsonFromTirFfbb(array $tirs, ?\DateTimeImmutable $fromDate, ?\DateTimeImmutable $toDate): array
    {
        $result = [];

        foreach ($tirs as $tir) {
            // Skip tirs sans position (pas de coordonnées extractibles depuis le PDF)
            $posX = $tir->getPositionX();
            $posY = $tir->getPositionY();
            if ($posX === null || $posY === null) {
                continue;
            }

            // Filtre de date sur la rencontre
            $dateMatch = $tir->getRencontre()?->getDate();
            if ($dateMatch !== null) {
                if ($fromDate !== null && $dateMatch < $fromDate) continue;
                if ($toDate   !== null && $dateMatch > $toDate)   continue;
            }

            // [V2.4] Coordonnées repère FFBB (fx/fy, 0-1) pour le terrain
            // "FFBB officiel" (portrait, panier en haut) :
            //   - ffbbX/ffbbY présents (lignes re-parsées) → valeurs brutes,
            //     précision maximale (pour-mille).
            //   - sinon FALLBACK : inversion de l'ancienne transformation
            //     zoneX = normY*0.46+0.04 / zoneY = normX (précision dégradée
            //     par l'arrondi 0-100 historique, mais affichable en attendant
            //     un re-parse : app:process-positions-tirs).
            if ($tir->getFfbbX() !== null && $tir->getFfbbY() !== null) {
                $fx = $tir->getFfbbX() / 1000.0;
                $fy = $tir->getFfbbY() / 1000.0;
            } else {
                $fx = $posY / 100.0;
                $fy = max(0.0, min(1.0, ($posX / 100.0 - 0.04) / 0.46));
            }

            // FFBB : tirs réussis uniquement → pas de % calculable (tentatives inconnues)
            // Couleur bleue fixe pour distinguer des séances entraînement (rouge/vert selon %)
            $result[] = [
                'seanceId'    => null,   // pas de SeanceTir — tir FFBB
                'rencontreId' => $tir->getRencontre()?->getId(), // pour filtrage JS par match
                'ffbb'        => true,   // flag pour le JS (affichage différencié)
                'adversaire'  => $tir->getRencontre()?->getAdversaire() ?? '',
                'date'        => $dateMatch?->format('Y-m-d') ?? '',
                'source'      => SeanceTir::SOURCE_MATCH,
                'x'           => $posX / 100.0,
                'y'           => $posY / 100.0,
                'fx'          => round($fx, 4),
                'fy'          => round($fy, 4),
                'typeTir'     => $tir->getTypeTir(),
                'reussis'     => 1,
                'tentatives'  => null,   // inconnu
                'pct'         => null,   // pas calculable
                'couleur'     => '#3b82f6', // bleu fixe
                'label'       => '✓',
            ];
        }

        return $result;
    }

    /**
     * Construit la liste des matchs FFBB pour le sélecteur de match.
     * Groupé par rencontre, trié date DESC.
     *
     * [V2.4b] Consomme les ZONES JSON (sortie de buildZonesJsonFromTirFfbb)
     * — exactement les points rendus sur le terrain — et non plus les
     * entités TirFfbb : le compteur 🏀 des cards et le nombre de points
     * bleus ne peuvent plus diverger (une seule source de vérité).
     *
     * @param array $zones Zones FFBB au format JSON (cf. buildZonesJsonFromTirFfbb)
     * @return array<int, array{id:int, date:string, date_label:string, adversaire:string, nb_tirs:int, types:array}>
     */
    private function buildMatchesList(array $zones): array
    {
        $grouped = [];
        foreach ($zones as $z) {
            $rid = $z['rencontreId'] ?? null;
            if ($rid === null) continue;

            if (!isset($grouped[$rid])) {
                $dateIso = (string) ($z['date'] ?? '');
                $grouped[$rid] = [
                    'id'          => $rid,
                    'date'        => $dateIso,
                    'date_label'  => $dateIso !== '' ? date('d/m', strtotime($dateIso)) : '??',
                    'adversaire'  => $z['adversaire'] ?? '?',
                    'nb_tirs'     => 0,
                    'types'       => ['2pt_int' => 0, '2pt_ext' => 0, '3pt' => 0, 'lancer' => 0],
                ];
            }
            $grouped[$rid]['nb_tirs']++;
            $type = $z['typeTir'] ?? '2pt_ext';
            if (isset($grouped[$rid]['types'][$type])) {
                $grouped[$rid]['types'][$type]++;
            }
        }
        // Trier par date DESC — les matchs sans aucun tir coordonné sont absents
        uasort($grouped, static fn($a, $b) => strcmp($b['date'], $a['date']));
        return array_values($grouped);
    }

    /**
     * Stats globales agrégées pour les badges de résumé.
     *
     * @param SeanceTir[] $seances
     * @param array       $extraZones  Zones supplémentaires déjà au format JSON (ex: TirFfbb)
     *                                  → même structure que buildZonesJson() : [reussis, tentatives, typeTir, ...]
     * @return array{totalTentatives: int, totalReussis: int, pctGlobal: float|null, parType: array}
     */
    private function buildStatsGlobales(array $seances, array $extraZones = []): array
    {
        $totTentatives = 0;
        $totReussis    = 0;
        $parType       = [];

        foreach (SeanceTir::TYPES_TIR as $type) {
            $parType[$type] = ['tentatives' => 0, 'reussis' => 0, 'pct' => null];
        }

        // Séances V2 (manuellement saisies + validées coach)
        foreach ($seances as $seance) {
            foreach ($seance->getZones() as $zone) {
                $totTentatives += $zone->getTentatives();
                $totReussis    += $zone->getReussis();
                $t = $zone->getTypeTir();
                $parType[$t]['tentatives'] += $zone->getTentatives();
                $parType[$t]['reussis']    += $zone->getReussis();
            }
        }

        // Tirs FFBB importés (source: match officiel)
        // tentatives est null pour les tirs FFBB (FFBB ne fournit que les réussites).
        // On n'ajoute PAS aux tentatives pour ne pas inventer un taux de réussite
        // → pct restera null pour les types purement FFBB, ce que la template gère.
        foreach ($extraZones as $z) {
            $reussis = (int) ($z['reussis'] ?? 1);
            $totReussis += $reussis; // total paniers inclut les matchs officels
            $t = $z['typeTir'] ?? '';
            if (isset($parType[$t])) {
                $parType[$t]['reussis'] += $reussis; // pour affichage compte
                // tentatives non incrémentées → pct calculé uniquement depuis les séances
            }
        }

        foreach ($parType as $type => &$data) {
            $data['pct'] = $data['tentatives'] > 0
                ? round($data['reussis'] / $data['tentatives'] * 100, 1)
                : null;
        }

        return [
            'totalTentatives' => $totTentatives,
            'totalReussis'    => $totReussis,
            'pctGlobal'       => $totTentatives > 0
                ? round($totReussis / $totTentatives * 100, 1)
                : null,
            'parType'         => $parType,
        ];
    }
}
