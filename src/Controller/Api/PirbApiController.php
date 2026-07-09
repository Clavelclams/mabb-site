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
 * SAISON : par défaut la saison courante calculée (SaisonService). Depuis
 * le lot 2 (sélecteur de saison), stats/saison accepte ?saison=YYYY-YYYY
 * (saisons passées uniquement, jamais de saison future) et /saisons
 * fournit la liste pour construire le menu déroulant côté app.
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
    public function statsSaison(Request $request): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        // Sélecteur de saison (B4 lot 2) : ?saison=YYYY-YYYY facultatif.
        //   - absent             → saison courante calculée (rétrocompatible).
        //   - présent & valide   → cette saison (passée ou courante).
        //   - présent & invalide → 400. isValide() s'appuie sur
        //     getSaisonsDisponibles() qui ne contient JAMAIS de saison future,
        //     donc une saison future (ou un format faux) est rejetée ici.
        $saison   = $this->saisonService->getSaisonCourante();
        $demandee = $request->query->get('saison');
        if ($demandee !== null && $demandee !== '') {
            if (!$this->saisonService->isValide($demandee)) {
                return new JsonResponse(
                    ['error' => 'Saison invalide ou non disponible.'],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $saison = $demandee;
        }

        $s = $this->statsAggregator->statsSaison($joueur, $saison);

        // Mapping snake_case serveur → camelCase du contrat types/pirb.ts
        return new JsonResponse([
            // Libellé de la saison servie (ex. "2025-2026") : l'app affiche la
            // puce de saison quand ce champ est présent (contrat StatsSaison.saison).
            'saison'    => $saison,
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
    // GET /api/pirb/saisons  [B4 lot 2] — liste pour le menu déroulant Stats
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Saisons sélectionnables dans l'app (menu Stats).
     *
     * Source unique : SaisonService::getSaisonsDisponibles() — de la saison
     * courante (bascule 1er juillet) jusqu'à 2023-2024, JAMAIS de saison
     * future. On renvoie aussi `courante` pour que l'app présélectionne la
     * bonne saison sans redupliquer la logique de bascule côté client.
     *
     * Pas lié au Joueur : la liste est la même pour tout le monde. L'auth
     * Bearer reste exigée (firewall api) mais aucune donnée personnelle ici.
     */
    #[Route('/api/pirb/saisons', name: 'api_pirb_saisons', methods: ['GET'])]
    public function saisons(): JsonResponse
    {
        return new JsonResponse([
            'courante' => $this->saisonService->getSaisonCourante(),
            'saisons'  => $this->saisonService->getSaisonsDisponibles(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/shot-chart
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/shot-chart', name: 'api_pirb_shot_chart', methods: ['GET'])]
    public function shotChart(Request $request): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        // Filtrage par saison (même contrat que /stats/saison) : ?saison=YYYY-YYYY.
        $saison   = $this->saisonService->getSaisonCourante();
        $demandee = $request->query->get('saison');
        if ($demandee !== null && $demandee !== '') {
            if (!$this->saisonService->isValide($demandee)) {
                return new JsonResponse(['error' => 'Saison invalide ou non disponible.'], Response::HTTP_BAD_REQUEST);
            }
            $saison = $demandee;
        }

        $tirs = [];

        // 1. Tirs Stats Live (ActionMatch), filtrés sur la saison choisie
        foreach ($this->shotChart->positionsTirs($joueur, null, $saison) as $t) {
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
            if ($tir->getRencontre()?->getSaison() !== $saison) { continue; } // saison choisie

            // Deux jeux de coordonnées existent sur TirFfbb :
            //   - ffbbX/ffbbY : pour-mille (0-1000), déjà dans le repère du
            //     contrat app (x latéral, y 0=fond → 1=médiane, panier en 0.5,0).
            //   - positionX/positionY : échelle 0-100, repère WEB (panier à
            //     gauche). C'est ce champ qui est réellement rempli pour les
            //     tirs FFBB (le web s'en sert). On le convertit vers le repère
            //     app : latéral = positionY, distance au panier = positionX.
            if ($tir->getFfbbX() !== null && $tir->getFfbbY() !== null) {
                $x = $tir->getFfbbX() / 1000.0;
                $y = $tir->getFfbbY() / 1000.0;
            } elseif ($tir->getPositionX() !== null && $tir->getPositionY() !== null) {
                $x = $tir->getPositionY() / 100.0;
                $y = $tir->getPositionX() / 100.0;
            } else {
                continue;
            }

            $tirs[] = [
                'x'      => $x,
                'y'      => $y,
                'reussi' => true,
                'zone'   => ShotChartCalculator::classerEnZone($x, $y),
                'source' => 'FFBB',
            ];
        }

        // Zones agrégées depuis LES MÊMES tirs (Live + FFBB) que la liste
        // ci-dessus. On N'utilise PAS statsParZone() du service : il ne compte
        // que les tirs Stats Live (positionsTirs ← ActionMatch), donc un joueur
        // 100 % FFBB verrait 8 zones à zéro. En agrégeant ici, l'agrégat inclut
        // les tirs FFBB. Le web reste inchangé (il continue d'appeler
        // statsParZone), donc aucun impact hors de cet endpoint API.
        $agg = [];
        foreach (ShotChartCalculator::ZONE_LIBELLES as $zone => $libelle) {
            $agg[$zone] = ['tentes' => 0, 'reussis' => 0]; // exhaustif : toutes les zones
        }
        foreach ($tirs as $t) {
            // Zone par zone = STATS LIVE uniquement. La FFBB ne fournit que les
            // tirs RÉUSSIS → l'inclure afficherait 100 % partout (trompeur). Les
            // tirs FFBB restent visibles sur le terrain (pastilles), pas ici.
            if ($t['source'] !== 'LIVE') {
                continue;
            }
            $z = $t['zone'];
            if (!isset($agg[$z])) {
                $agg[$z] = ['tentes' => 0, 'reussis' => 0];
            }
            $agg[$z]['tentes']++;
            if ($t['reussi']) {
                $agg[$z]['reussis']++;
            }
        }

        $zones = [];
        foreach ($agg as $zone => $stats) {
            $zones[] = [
                'zone'        => $zone,
                'libelle'     => ShotChartCalculator::ZONE_LIBELLES[$zone] ?? $zone,
                'tentes'      => $stats['tentes'],
                'reussis'     => $stats['reussis'],
                // Même format que statsParZone : arrondi à 1 décimale, null si 0 tenté.
                'pourcentage' => $stats['tentes'] > 0
                    ? round($stats['reussis'] / $stats['tentes'] * 100, 1)
                    : null,
            ];
        }

        return new JsonResponse(['tirs' => $tirs, 'zones' => $zones]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/commu  → JoueurPublicCard[]
    // Les VRAIES joueuses du club (coéquipières + autres du club). Les profils
    // ne sont pas encore publics (elles n'ont pas de compte app) : on n'expose
    // donc que le minimum club (nom, équipe, poste, photo). Pas de stats, pas
    // de suivi encore (entité Follow à venir). Non cliquable côté app.
    // ⚠️ RGPD : ces joueuses sont mineures — cette liste reste intra-club
    // (visible seulement par un membre du club connecté). Le consentement
    // parental pour une exposition plus large reste à cadrer avant tout public.
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/commu', name: 'api_pirb_commu', methods: ['GET'])]
    public function commu(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $club = $moi->getClub();
        if ($club === null) {
            return new JsonResponse([]);
        }

        $saison = $this->saisonService->getSaisonCourante();
        $monEquipe = $moi->equipePourSaison($saison) ?? $moi->getEquipe();
        $monEquipeId = $monEquipe?->getId();
        $base = $request->getSchemeAndHttpHost();

        $cartes = [];
        foreach ($this->joueurRepo->findByClub($club->getId()) as $j) {
            if (!$j instanceof Joueur) { continue; }
            if ($j->getId() === $moi->getId()) { continue; } // pas soi-même
            if (!$j->isActive()) { continue; }

            $equipe = $j->equipePourSaison($saison) ?? $j->getEquipe();

            $cartes[] = [
                'id'             => $j->getId(),
                // Pas de pseudo tant qu'elle n'a pas de compte app → Prénom Nom
                // (donnée club, non publique hors du club).
                'pseudo'         => trim(($j->getPrenom() ?? '') . ' ' . ($j->getNom() ?? '')),
                'photoUrl'       => $j->getPhotoPath() !== null
                    ? $base . '/' . ltrim($j->getPhotoPath(), '/')
                    : null,
                'club'           => $club->getNom(),
                'equipe'         => $equipe?->getNom(),
                'poste'          => $j->getPoste(),
                'suivie'         => false, // entité Follow pas encore posée
                'estCoequipiere' => $monEquipeId !== null && $equipe?->getId() === $monEquipeId,
            ];
        }

        return new JsonResponse($cartes);
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
