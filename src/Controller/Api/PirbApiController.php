<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\TirFfbb;
use App\Gamification\BadgeCatalog;
use App\Gamification\NiveauCatalog;
use App\Gamification\XpCalculator;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\TirFfbbRepository;
use App\Service\SaisonService;
use App\Service\Stats\JoueurStatsAggregator;
use App\Service\Stats\ShotChartCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiController — [B4 phase 1, 06/07/2026]
 *
 * Les 5 endpoints CŒUR consommés par l'app mobile PIRB (Expo).
 * Le CONTRAT (noms de champs, casse, null) est celui de
 * `Pirb store/src/types/pirb.ts` — c'est l'app qui fixe la forme,
 * ce contrôleur s'y plie. Toute évolution = mettre à jour LES DEUX.
 *
 *   GET /api/pirb/profil        → JoueurProfil
 *   GET /api/pirb/stats/saison  → StatsSaison
 *   GET /api/pirb/shot-chart    → { tirs: TirShotChart[], zones: StatsZone[] }
 *   GET /api/pirb/badges        → Badge[]
 *   GET /api/pirb/niveau        → NiveauInfo
 *
 * SÉCURITÉ / ISOLATION : le user vient du Bearer token (firewall api).
 * Toutes les données sont dérivées de SA fiche Joueur — même modèle
 * d'isolation que l'espace web PIRB. Pas de paramètre {id} : impossible
 * de demander les données d'une autre joueuse.
 *
 * La SAISON servie = saison courante calculée (SaisonService) — l'app
 * ne gère pas encore de sélecteur de saison (P0).
 */
class PirbApiController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly JoueurStatsAggregator $statsAggregator,
        private readonly ShotChartCalculator $shotChart,
        private readonly TirFfbbRepository $tirFfbbRepo,
        private readonly JoueurBadgeRepository $badgeRepo,
        private readonly XpCalculator $xpCalculator,
        private readonly SaisonService $saisonService,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/profil
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/profil', name: 'api_pirb_profil', methods: ['GET'])]
    public function profil(Request $request): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        $saison = $this->saisonService->getSaisonCourante();
        $equipe = $joueur->equipePourSaison($saison) ?? $joueur->getEquipe();

        // URL absolue de la photo (l'app mobile n'a pas de "même domaine")
        $photoUrl = $joueur->getPhotoPath() !== null
            ? $request->getSchemeAndHttpHost() . '/' . ltrim($joueur->getPhotoPath(), '/')
            : null;

        return new JsonResponse([
            'id'             => $joueur->getId(),
            'prenom'         => $joueur->getPrenom(),
            'nom'            => $joueur->getNom(),
            'dateNaissance'  => $joueur->getDateNaissance()?->format('Y-m-d'),
            'poste'          => $joueur->getPoste(),
            'numeroMaillot'  => $joueur->getNumeroMaillot(),
            'licence'        => $joueur->getLicence(),
            'photoUrl'       => $photoUrl,
            'bio'            => $joueur->getBio(),
            'profilPublic'   => $joueur->isProfilPublic(),
            'liensSociaux'   => $joueur->getLiensSociaux() ?? new \stdClass(),
            'badgesEpingles' => $joueur->getBadgesEpingles() ?? [],
            'club'           => [
                'id'  => $joueur->getClub()?->getId(),
                'nom' => $joueur->getClub()?->getNom(),
            ],
            'equipe'         => $equipe !== null ? [
                'id'        => $equipe->getId(),
                'nom'       => $equipe->getNom(),
                'categorie' => $equipe->getCategorie(),
            ] : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/stats/saison
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/stats/saison', name: 'api_pirb_stats_saison', methods: ['GET'])]
    public function statsSaison(): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        $s = $this->statsAggregator->statsSaison($joueur, $this->saisonService->getSaisonCourante());

        // Mapping snake_case serveur → camelCase du contrat types/pirb.ts
        return new JsonResponse([
            'nbMatchs'  => $s['nb_matchs'],
            'titulaire' => $s['titulaire'],
            'moyennes'  => $s['moyennes'], // clés identiques (points, rebonds, passes, minutes, eval)
            'totaux'    => [
                'points'  => $s['totaux']['points'],
                'rebOff'  => $s['totaux']['reb_off'],
                'rebDef'  => $s['totaux']['reb_def'],
                'passes'  => $s['totaux']['passes'],
                'inter'   => $s['totaux']['inter'],
                'contres' => $s['totaux']['contres'],
                'fautes'  => $s['totaux']['fautes'],
                'pertes'  => $s['totaux']['pertes'],
            ],
            'pourcentages' => $s['pourcentages'], // tirs2 / tirs3 / lf, null si 0 tenté
            'graph'        => array_map(static fn(array $g) => [
                'date'    => $g['date'],
                'points'  => $g['points'],
                'eval'    => $g['eval'],
                'rebonds' => $g['rebonds'],
            ], $s['graph_progression']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/shot-chart
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/shot-chart', name: 'api_pirb_shot_chart', methods: ['GET'])]
    public function shotChart(): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        $tirs = [];

        // 1. Tirs Stats Live (ActionMatch, coordonnées natives 0-1, source LIVE)
        foreach ($this->shotChart->positionsTirs($joueur) as $t) {
            $tirs[] = [
                'x'      => $t['x'],
                'y'      => $t['y'],
                'reussi' => $t['reussi'],
                'zone'   => $t['zone'],
                'source' => 'LIVE',
            ];
        }

        // 2. Tirs FFBB (réussis uniquement — la FFBB ne fournit pas les ratés).
        //    ffbbX/ffbbY sont en pour-mille dans le MÊME repère que le contrat
        //    (x lateral 0-1, y 0=ligne de fond → 1=médiane, panier en 0.5,0).
        foreach ($this->tirFfbbRepo->findForJoueur($joueur) as $tir) {
            if ($tir->getSource() !== TirFfbb::SOURCE_FFBB) { continue; }
            if ($tir->getFfbbX() === null || $tir->getFfbbY() === null) { continue; }
            $x = $tir->getFfbbX() / 1000.0;
            $y = $tir->getFfbbY() / 1000.0;
            $tirs[] = [
                'x'      => $x,
                'y'      => $y,
                'reussi' => true,
                'zone'   => ShotChartCalculator::classerEnZone($x, $y),
                'source' => 'FFBB',
            ];
        }

        // Zones agrégées (les 8 zones officielles, toujours exhaustives)
        $zones = [];
        foreach ($this->shotChart->statsParZone($joueur) as $zone => $stats) {
            $zones[] = [
                'zone'        => $zone,
                'libelle'     => ShotChartCalculator::ZONE_LIBELLES[$zone] ?? $zone,
                'tentes'      => $stats['tentes'],
                'reussis'     => $stats['reussis'],
                'pourcentage' => $stats['pourcentage'],
            ];
        }

        return new JsonResponse(['tirs' => $tirs, 'zones' => $zones]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/badges
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/badges', name: 'api_pirb_badges', methods: ['GET'])]
    public function badges(): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        // Dates de déblocage indexées par code badge
        $debloques = [];
        foreach ($this->badgeRepo->badgesPourJoueur($joueur) as $jb) {
            $debloques[$jb->getCodeBadge()] = $jb->getDebloqueAt()?->format('Y-m-d');
        }

        // Catalogue COMPLET (l'app affiche aussi les badges verrouillés)
        $badges = [];
        foreach (BadgeCatalog::all() as $code => $def) {
            $badges[] = [
                'code'        => $code,
                'libelle'     => $def['nom'],
                'description' => $def['description'],
                'axe'         => $def['axe'],
                'debloqueLe'  => $debloques[$code] ?? null,
            ];
        }

        return new JsonResponse($badges);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/niveau
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/niveau', name: 'api_pirb_niveau', methods: ['GET'])]
    public function niveau(): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        $xp = $this->xpCalculator->xpSaison($joueur);
        $n  = NiveauCatalog::depuisXp($xp);

        return new JsonResponse([
            'niveau'          => $n['niveau'],
            'nom'             => $n['nom'],
            'couleur'         => $n['couleur'],
            'xpActuel'        => $n['xp_actuel'],
            'xpPalierActuel'  => $n['xp_palier_actuel'],
            'xpPalierSuivant' => $n['xp_palier_suivant'],
            'progresPct'      => $n['progres_pct'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Privé
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fiche Joueur du user authentifié par Bearer, ou 404 JSON.
     * Même règle que l'espace web PIRB : pas de fiche liée = pas de données.
     */
    private function joueurOu404(): Joueur|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            return new JsonResponse(
                ['error' => 'Aucune fiche joueuse liée à ce compte. Contacte le staff du club.'],
                Response::HTTP_NOT_FOUND
            );
        }
        return $joueur;
    }
}
