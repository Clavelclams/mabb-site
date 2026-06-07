<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;

/**
 * ShotChartCalculator — calcul des shot charts et statistiques par zone.
 *
 * COORDONNÉES NORMALISÉES :
 *   - Position X dans [0, 1] : 0 = touche gauche, 1 = touche droite
 *   - Position Y dans [0, 1] : 0 = ligne de fond (sous le panier), 1 = milieu de terrain
 *   - Le terrain de tir est TOUJOURS représenté comme le DEMI-TERRAIN OFFENSIF
 *     (peu importe le côté physique pendant le match — on normalise à la saisie)
 *   - Le panier est en (0.5, 0.0)
 *
 * ZONES DU TERRAIN (basé sur le règlement FIBA + intuition visuelle) :
 *   - RAQUETTE : "key area" / la raquette/zone sous le panier
 *   - COURTE_DISTANCE : entre la raquette et la ligne des 6.75m
 *   - MI_DISTANCE : à mi-distance (avant la ligne 3pts)
 *   - 3PTS_COIN_G / 3PTS_COIN_D : tirs 3pts depuis les coins
 *   - 3PTS_AILE_G / 3PTS_AILE_D : tirs 3pts depuis les ailes
 *   - 3PTS_HAUT : tirs 3pts depuis le sommet de la clef
 *
 * Cette classification permet :
 *   1. De donner du contexte au shot chart visuel (couleurs par zone)
 *   2. De calculer les % de réussite par zone (zone forte / zone faible)
 *   3. De déterminer le "shoot préférentiel" d'une joueuse (PIRB)
 *
 * Coordonnées des zones (en proportion du demi-terrain) :
 *   La raquette FIBA fait 4.9m × 5.8m sur un terrain de 28×15m.
 *   Donc largeur ~33% du terrain (centrée), profondeur ~40% du demi-terrain.
 *   La ligne 3pts est à 6.75m, soit ~45% du demi-terrain depuis la ligne de fond.
 */
final class ShotChartCalculator
{
    // ====================================================================
    // CONSTANTES DES ZONES
    // ====================================================================

    public const ZONE_RAQUETTE         = 'raquette';
    public const ZONE_COURTE_DISTANCE  = 'courte_distance';
    public const ZONE_MI_DISTANCE      = 'mi_distance';
    public const ZONE_3PTS_COIN_G      = '3pts_coin_g';
    public const ZONE_3PTS_COIN_D      = '3pts_coin_d';
    public const ZONE_3PTS_AILE_G      = '3pts_aile_g';
    public const ZONE_3PTS_AILE_D      = '3pts_aile_d';
    public const ZONE_3PTS_HAUT        = '3pts_haut';

    public const ZONES = [
        self::ZONE_RAQUETTE,
        self::ZONE_COURTE_DISTANCE,
        self::ZONE_MI_DISTANCE,
        self::ZONE_3PTS_COIN_G,
        self::ZONE_3PTS_COIN_D,
        self::ZONE_3PTS_AILE_G,
        self::ZONE_3PTS_AILE_D,
        self::ZONE_3PTS_HAUT,
    ];

    /** Libellés humains pour l'UI */
    public const ZONE_LIBELLES = [
        self::ZONE_RAQUETTE         => 'Raquette',
        self::ZONE_COURTE_DISTANCE  => 'Courte distance',
        self::ZONE_MI_DISTANCE      => 'Mi-distance',
        self::ZONE_3PTS_COIN_G      => '3pts coin gauche',
        self::ZONE_3PTS_COIN_D      => '3pts coin droit',
        self::ZONE_3PTS_AILE_G      => '3pts aile gauche',
        self::ZONE_3PTS_AILE_D      => '3pts aile droite',
        self::ZONE_3PTS_HAUT        => '3pts sommet',
    ];

    public function __construct(
        private readonly ActionMatchRepository $actionMatchRepository,
    ) {}

    /**
     * Liste des tirs d'une joueuse sur une saison (ou un match précis).
     * Format prêt pour rendu shot chart (SVG côté Twig).
     *
     * @return array<int, array{x: float, y: float, reussi: bool, type: string, zone: string, match: ?int, date: ?string, adversaire: ?string}>
     */
    public function positionsTirs(Joueur $joueur, ?Rencontre $rencontre = null, ?string $saison = null): array
    {
        $tirs = $this->actionMatchRepository->actionsTirsJoueur($joueur, $rencontre, $saison);

        $resultat = [];
        foreach ($tirs as $tir) {
            $x = $tir->getPositionX();
            $y = $tir->getPositionY();
            if ($x === null || $y === null) {
                continue; // sécurité — devrait pas arriver (tir = position obligatoire en théorie)
            }

            $rencontreTir = $tir->getRencontre();
            $resultat[] = [
                'x'         => $x,
                'y'         => $y,
                'reussi'    => $tir->estReussi(),
                'type'      => $tir->getType(),
                'zone'      => self::classerEnZone($x, $y, $tir->getType()),
                'match'     => $rencontreTir?->getId(),
                'date'      => $rencontreTir?->getDate()?->format('Y-m-d'),
                'adversaire' => $rencontreTir?->getAdversaire(),
            ];
        }

        return $resultat;
    }

    /**
     * Stats agrégées par zone pour une joueuse.
     *
     * Pour chaque zone, calcule :
     *   - tentes : nb de tirs tentés
     *   - reussis : nb de tirs réussis
     *   - pourcentage : reussis / tentes × 100 (null si 0 tenté)
     *   - points : nb de points marqués (2 ou 3 selon zone)
     *
     * @return array<string, array{tentes: int, reussis: int, pourcentage: float|null, points: int}>
     */
    public function statsParZone(Joueur $joueur, ?string $saison = null): array
    {
        $tirs = $this->positionsTirs($joueur, null, $saison);

        // Initialise toutes les zones à 0 pour avoir un retour exhaustif
        $stats = [];
        foreach (self::ZONES as $zone) {
            $stats[$zone] = ['tentes' => 0, 'reussis' => 0, 'pourcentage' => null, 'points' => 0];
        }

        foreach ($tirs as $tir) {
            $zone = $tir['zone'];
            $stats[$zone]['tentes']++;
            if ($tir['reussi']) {
                $stats[$zone]['reussis']++;
                // Points par tir : 3 pour zones 3pts, 2 sinon
                $stats[$zone]['points'] += self::estZone3pts($zone) ? 3 : 2;
            }
        }

        // Calcul du pourcentage final pour chaque zone (sans division par 0)
        foreach ($stats as $zone => $row) {
            $stats[$zone]['pourcentage'] = $row['tentes'] > 0
                ? round($row['reussis'] / $row['tentes'] * 100, 1)
                : null;
        }

        return $stats;
    }

    /**
     * Détermine le "shoot préférentiel" : la zone avec le plus de tirs tentés.
     * Critère secondaire : meilleure % réussite (tie-breaker).
     *
     * Utilisé sur la fiche joueuse (PIRB) pour mettre en avant la spécialité.
     *
     * @return array{zone: string|null, libelle: string|null, tentes: int, pourcentage: float|null}
     */
    public function shootPreferentiel(Joueur $joueur, ?string $saison = null): array
    {
        $stats = $this->statsParZone($joueur, $saison);

        $best = null;
        $bestTentes = 0;
        $bestPct = -1.0;

        foreach ($stats as $zone => $row) {
            if ($row['tentes'] === 0) continue;

            // Critère principal : nb tentés. Tie-breaker : meilleur %
            $isBetter = ($row['tentes'] > $bestTentes)
                || ($row['tentes'] === $bestTentes && ($row['pourcentage'] ?? 0) > $bestPct);

            if ($isBetter) {
                $best = $zone;
                $bestTentes = $row['tentes'];
                $bestPct = $row['pourcentage'] ?? 0;
            }
        }

        return [
            'zone'         => $best,
            'libelle'      => $best ? self::ZONE_LIBELLES[$best] : null,
            'tentes'       => $bestTentes,
            'pourcentage'  => $best !== null ? $stats[$best]['pourcentage'] : null,
        ];
    }

    // ====================================================================
    // CLASSIFICATION GÉOMÉTRIQUE DES TIRS EN ZONES
    // ====================================================================

    /**
     * Détermine la zone d'un tir à partir de ses coordonnées normalisées.
     *
     * Géométrie simplifiée (assez précise pour la V1) :
     *   - Si type = 3pts → on classe par X (gauche/droite/centre)
     *   - Sinon : on regarde la distance au panier
     *
     * Coordonnées : 0,0 = bas-gauche du demi-terrain ; 1,1 = haut-droit.
     * Panier à (0.5, 0.0). Ligne 3pts à ~0.45 de Y du panier.
     */
    public static function classerEnZone(float $x, float $y, ?string $type = null): string
    {
        // Si le type est connu et indique un 3pts, on classe directement par X
        if ($type === ActionMatch::TYPE_TIR_3PT_REUSSI || $type === ActionMatch::TYPE_TIR_3PT_RATE) {
            // Y faible + X aux extrêmes = coin
            if ($y < 0.20 && $x < 0.20) return self::ZONE_3PTS_COIN_G;
            if ($y < 0.20 && $x > 0.80) return self::ZONE_3PTS_COIN_D;
            // Sommet (centre-haut)
            if ($x > 0.35 && $x < 0.65) return self::ZONE_3PTS_HAUT;
            // Sinon = aile
            return $x < 0.5 ? self::ZONE_3PTS_AILE_G : self::ZONE_3PTS_AILE_D;
        }

        // Tirs 2pts : on calcule la distance au panier (0.5, 0.0)
        $dx = $x - 0.5;
        $dy = $y;
        $distance = sqrt($dx * $dx + $dy * $dy);

        // Largeur raquette ~ 0.33 centrée = X dans [0.33, 0.67]
        $dansLargeurRaquette = ($x >= 0.33 && $x <= 0.67);

        if ($distance < 0.15 && $dansLargeurRaquette) {
            return self::ZONE_RAQUETTE;
        }
        if ($distance < 0.30) {
            return self::ZONE_COURTE_DISTANCE;
        }
        return self::ZONE_MI_DISTANCE;
    }

    /**
     * Helper : est-ce une zone 3 points ?
     */
    public static function estZone3pts(string $zone): bool
    {
        return str_starts_with($zone, '3pts_');
    }
}
