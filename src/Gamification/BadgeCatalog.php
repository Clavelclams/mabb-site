<?php

namespace App\Gamification;

/**
 * BadgeCatalog — catalogue statique des badges disponibles.
 *
 * Un badge est identifié par un CODE (string, ex: 'STREAK_10') et porte :
 *   - libellé visible
 *   - description courte
 *   - icône Bootstrap Icons
 *   - axe (régularité / performance / bénévolat / transverse)
 *   - flag par_saison : si true, badge re-déblocable chaque saison
 *
 * Volontairement pas une enum PHP : on veut pouvoir ajouter des badges en
 * V1.1, V2, etc. sans casser les données existantes (les badges déjà
 * débloqués en base sont juste référencés par leur CODE string).
 */
class BadgeCatalog
{
    public const AXE_REGULARITE = 'regularite';
    public const AXE_PERFORMANCE = 'performance';
    public const AXE_BENEVOLAT = 'benevolat';
    public const AXE_TRANSVERSE = 'transverse';

    /**
     * Codes badges Axe C (bénévolat / vie de club). Activés en V1.1.
     * Déclenchés par les Missions (créées manuellement ou auto via Evenement).
     */
    public const C_FIRST_MISSION  = 'C_FIRST_MISSION';
    public const C_BENEVOLE_5     = 'C_BENEVOLE_5';
    public const C_BENEVOLE_10    = 'C_BENEVOLE_10';
    public const C_POLYVALENT     = 'C_POLYVALENT';
    public const C_TABLE_5        = 'C_TABLE_5';
    public const C_AG_PRESENT     = 'C_AG_PRESENT';

    /**
     * Codes badges Axe A (régularité). Tous activés en V1.0.
     */
    public const A_FIRST_TRAINING = 'A_FIRST_TRAINING';
    public const A_FIRST_MATCH    = 'A_FIRST_MATCH';
    public const A_STREAK_5       = 'A_STREAK_5';
    public const A_STREAK_10      = 'A_STREAK_10';
    public const A_STREAK_20      = 'A_STREAK_20';
    public const A_MONTH_100      = 'A_MONTH_100';
    public const A_QUARTER_100    = 'A_QUARTER_100';
    public const A_MARATHON_30    = 'A_MARATHON_30';
    public const A_MATCH_10       = 'A_MATCH_10';
    public const A_VETERAN_50     = 'A_VETERAN_50';
    public const A_SEASON_90      = 'A_SEASON_90';

    /**
     * Définitions complètes des badges.
     *
     * @return array<string, array{
     *   nom: string, description: string, icone: string, axe: string, par_saison: bool
     * }>
     */
    public static function all(): array
    {
        return [
            // ============ AXE A — RÉGULARITÉ ============
            self::A_FIRST_TRAINING => [
                'nom' => 'Premier pas',
                'description' => 'Présent(e) à ta première séance d\'entraînement.',
                'icone' => 'bi-flag',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => false,
            ],
            self::A_FIRST_MATCH => [
                'nom' => 'Première rencontre',
                'description' => 'Présent(e) à ta première rencontre officielle.',
                'icone' => 'bi-trophy',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => false,
            ],
            self::A_STREAK_5 => [
                'nom' => 'Régulier',
                'description' => '5 séances présentes d\'affilée sans absence.',
                'icone' => 'bi-fire',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_STREAK_10 => [
                'nom' => 'Pilier',
                'description' => '10 séances présentes d\'affilée. Tu es un pilier du groupe.',
                'icone' => 'bi-shield-check',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_STREAK_20 => [
                'nom' => 'Inébranlable',
                'description' => '20 séances présentes d\'affilée. Niveau pro.',
                'icone' => 'bi-gem',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_MONTH_100 => [
                'nom' => 'Modèle du mois',
                'description' => '100% de présence sur 1 mois calendaire complet.',
                'icone' => 'bi-star-fill',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_QUARTER_100 => [
                'nom' => 'Indispensable',
                'description' => '100% de présence sur 3 mois consécutifs.',
                'icone' => 'bi-award-fill',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_MARATHON_30 => [
                'nom' => 'Marathonien',
                'description' => '30 séances cumulées sur la saison. Endurance prouvée.',
                'icone' => 'bi-stopwatch',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_MATCH_10 => [
                'nom' => 'Joueur de match',
                'description' => '10 rencontres officielles disputées.',
                'icone' => 'bi-controller',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],
            self::A_VETERAN_50 => [
                'nom' => 'Vétéran',
                'description' => '50 séances cumulées toutes saisons confondues.',
                'icone' => 'bi-mortarboard',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => false,
            ],
            self::A_SEASON_90 => [
                'nom' => 'Saison parfaite',
                'description' => '90% de présence ou plus sur toute la saison.',
                'icone' => 'bi-crown',
                'axe' => self::AXE_REGULARITE,
                'par_saison' => true,
            ],

            // ============ AXE C — BÉNÉVOLAT / VIE DE CLUB ============
            self::C_FIRST_MISSION => [
                'nom' => 'Premier engagement',
                'description' => 'Première mission bénévole accomplie pour le club.',
                'icone' => 'bi-hand-thumbs-up',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],
            self::C_BENEVOLE_5 => [
                'nom' => 'Bénévole',
                'description' => '5 missions bénévoles dans la saison.',
                'icone' => 'bi-heart-fill',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => true,
            ],
            self::C_BENEVOLE_10 => [
                'nom' => 'Pilier du club',
                'description' => '10 missions bénévoles dans la saison. Le club tient grâce à toi.',
                'icone' => 'bi-building',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => true,
            ],
            self::C_POLYVALENT => [
                'nom' => 'Polyvalent',
                'description' => '3 types de missions différents dans la saison.',
                'icone' => 'bi-diagram-3',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => true,
            ],
            self::C_TABLE_5 => [
                'nom' => 'Officiel de table',
                'description' => '5 tenues de table de marque ou chrono.',
                'icone' => 'bi-clipboard-check',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => true,
            ],
            self::C_AG_PRESENT => [
                'nom' => 'Engagé',
                'description' => 'Présent(e) à l\'Assemblée Générale du club.',
                'icone' => 'bi-megaphone',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => true,
            ],
        ];
    }

    /**
     * Récupère les infos d'un badge par son code, ou null si le code est inconnu.
     * Renvoyer null permet à l'UI d'afficher proprement les anciens badges
     * dont on aurait supprimé la définition.
     *
     * @return array{nom: string, description: string, icone: string, axe: string, par_saison: bool}|null
     */
    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /**
     * Codes badges actifs pour un axe donné.
     *
     * @return string[]
     */
    public static function codesParAxe(string $axe): array
    {
        return array_keys(array_filter(
            self::all(),
            fn($b) => $b['axe'] === $axe
        ));
    }
}
