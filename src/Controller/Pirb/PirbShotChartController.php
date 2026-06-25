<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\SeanceTir;
use App\Entity\Sport\ZoneTir;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\SeanceTirRepository;
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
        private readonly JoueurRepository $joueurRepo,
        private readonly SeanceTirRepository $seanceTirRepo,
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
    public function index(Request $request): Response
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
        $zonesJson = $this->buildZonesJson($seancesValidees);

        // --- Stats globales (badges résumé) ---
        $statsGlobales = $this->buildStatsGlobales($seancesValidees);

        return $this->render('pirb/shot_chart/index.html.twig', [
            'joueur'            => $joueur,
            'seances_validees'  => $seancesValidees,
            'seances_en_attente'=> array_values($seancesEnAttente),
            'zones_json'        => json_encode($zonesJson),
            'progression_data'  => json_encode($progressionData),
            'stats_globales'    => $statsGlobales,
            // Filtres actuels (pour pré-remplir les inputs)
            'filtre_source'     => $source,
            'filtre_from'       => $from,
            'filtre_to'         => $to,
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

        // Pas dans le futur
        if ($dateSeance > new \DateTimeImmutable('today')) {
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
     * Stats globales agrégées pour les badges de résumé.
     *
     * @param SeanceTir[] $seances
     * @return array{totalTentatives: int, totalReussis: int, pctGlobal: float|null, parType: array}
     */
    private function buildStatsGlobales(array $seances): array
    {
        $totTentatives = 0;
        $totReussis    = 0;
        $parType       = [];

        foreach (SeanceTir::TYPES_TIR as $type) {
            $parType[$type] = ['tentatives' => 0, 'reussis' => 0, 'pct' => null];
        }

        foreach ($seances as $seance) {
            foreach ($seance->getZones() as $zone) {
                $totTentatives += $zone->getTentatives();
                $totReussis    += $zone->getReussis();
                $t = $zone->getTypeTir();
                $parType[$t]['tentatives'] += $zone->getTentatives();
                $parType[$t]['reussis']    += $zone->getReussis();
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
