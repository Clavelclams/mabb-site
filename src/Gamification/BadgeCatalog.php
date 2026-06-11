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
    public const AXE_PERFORMANCE = 'performance';     // sportif (basket — V2 via stats FFBB)
    public const AXE_BENEVOLAT = 'benevolat';
    public const AXE_EMPLOYE = 'employe';             // performance pro (salariés/SC/alternants)
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
     * Sous-série SPECTATEUR (V1.4) — valorise ceux qui viennent soutenir
     * sans tâche officielle (parents, copains de joueuses, ancien-licencié
     * qui passe au club...). Chaque badge déclenché à N missions cumulées
     * de type Mission::TYPE_SPECTATEUR (toutes saisons confondues).
     */
    public const C_SPECTATEUR_FIRST    = 'C_SPECTATEUR_FIRST';     // 1ère présence
    public const C_SPECTATEUR_5        = 'C_SPECTATEUR_5';         // 5
    public const C_SPECTATEUR_10       = 'C_SPECTATEUR_10';        // 10
    public const C_SPECTATEUR_AGUERRI  = 'C_SPECTATEUR_AGUERRI';   // 20
    public const C_SPECTATEUR_FIDELE   = 'C_SPECTATEUR_FIDELE';    // 50 — top fan

    /**
     * Codes badges Axe D (performance employé / salarié / SC).
     * Compteur dédié au travail "dans le cadre du poste rémunéré".
     * Sert au président pour valoriser les employés sans polluer le
     * bénévolat. Élection employé du mois, SC du mois, etc.
     */
    public const D_FIRST_JOB_MISSION = 'D_FIRST_JOB_MISSION';
    public const D_JOB_10            = 'D_JOB_10';
    public const D_JOB_30            = 'D_JOB_30';
    public const D_JOB_50            = 'D_JOB_50';
    public const D_JOB_100           = 'D_JOB_100';
    public const D_EMPLOYE_DU_MOIS   = 'D_EMPLOYE_DU_MOIS';   // attribué manuellement par président

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
     * B18 — Codes badges Axe B (Performance basket).
     * Déclenchés depuis les EvaluationMatch / SessionStatsLive promues.
     * Permettent au PIRB d'avoir un bilan 4 axes complet (A/B/C/D).
     */
    public const B_FIRST_POINT       = 'B_FIRST_POINT';        // 1er point inscrit
    public const B_DOUBLE_FIGURE     = 'B_DOUBLE_FIGURE';      // 10+ points sur un match
    public const B_TRIPLE_DOUBLE     = 'B_TRIPLE_DOUBLE';      // 10+ dans 3 cat (pts/reb/passes)
    public const B_REBOND_MAITRE     = 'B_REBOND_MAITRE';      // 50 rebonds saison
    public const B_DISTRIBUTEUR      = 'B_DISTRIBUTEUR';       // 30 passes décisives saison
    public const B_ADRESSE_3PTS      = 'B_ADRESSE_3PTS';       // 40%+ à 3pts (min 30 tentatives)
    public const B_SNIPER_LF         = 'B_SNIPER_LF';          // 80%+ aux LF (min 20)
    public const B_REGULARITE_MATCH  = 'B_REGULARITE_MATCH';   // joué 80%+ des matchs
    public const B_TOP_SCOREUSE      = 'B_TOP_SCOREUSE';       // top scoreuse équipe sur un match
    public const B_PERFORMANCE_SAISON = 'B_PERFORMANCE_SAISON';// MVP saison équipe (manuel)

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

            // ============ AXE B — PERFORMANCE BASKET (B18) ============
            self::B_FIRST_POINT => [
                'nom' => 'Premier panier',
                'description' => 'Tu as inscrit ton premier point officiel. Bienvenue dans le club !',
                'icone' => 'bi-1-circle-fill',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => false,
            ],
            self::B_DOUBLE_FIGURE => [
                'nom' => 'Double figure',
                'description' => '10 points ou plus sur un seul match.',
                'icone' => 'bi-graph-up-arrow',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_TRIPLE_DOUBLE => [
                'nom' => 'Triple-double',
                'description' => '10+ dans 3 catégories sur un match (points, rebonds, passes…).',
                'icone' => 'bi-gem',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_REBOND_MAITRE => [
                'nom' => 'Maître du rebond',
                'description' => '50 rebonds cumulés sur la saison.',
                'icone' => 'bi-arrow-up-circle-fill',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_DISTRIBUTEUR => [
                'nom' => 'Distributrice',
                'description' => '30 passes décisives sur la saison.',
                'icone' => 'bi-share',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_ADRESSE_3PTS => [
                'nom' => 'Adresse longue distance',
                'description' => '40 % ou plus à 3pts (minimum 30 tentatives).',
                'icone' => 'bi-bullseye',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_SNIPER_LF => [
                'nom' => 'Sniper aux lancers',
                'description' => '80 % ou plus aux lancers francs (minimum 20 tentatives).',
                'icone' => 'bi-crosshair',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_REGULARITE_MATCH => [
                'nom' => 'Toujours là',
                'description' => 'Joué au moins 80 % des matchs de la saison.',
                'icone' => 'bi-calendar-check',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_TOP_SCOREUSE => [
                'nom' => 'Top scoreuse',
                'description' => 'Meilleure marqueuse de ton équipe sur un match.',
                'icone' => 'bi-trophy-fill',
                'axe' => self::AXE_PERFORMANCE,
                'par_saison' => true,
            ],
            self::B_PERFORMANCE_SAISON => [
                'nom' => 'MVP de la saison',
                'description' => 'Meilleure performance globale de ton équipe sur la saison.',
                'icone' => 'bi-award-fill',
                'axe' => self::AXE_PERFORMANCE,
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

            // ── Sous-série SPECTATEUR (V1.4) ──
            self::C_SPECTATEUR_FIRST => [
                'nom' => 'Premier supporter',
                'description' => 'Première venue en spectateur(rice) au club.',
                'icone' => 'bi-eye-fill',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],
            self::C_SPECTATEUR_5 => [
                'nom' => 'Supporter régulier',
                'description' => '5 venues en spectateur(rice) cumulées.',
                'icone' => 'bi-emoji-smile',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],
            self::C_SPECTATEUR_10 => [
                'nom' => 'Acte de présence',
                'description' => '10 venues en spectateur(rice). On commence à te reconnaître.',
                'icone' => 'bi-hand-thumbs-up-fill',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],
            self::C_SPECTATEUR_AGUERRI => [
                'nom' => 'Spectateur aguerri',
                'description' => '20 venues en spectateur(rice). Habitué(e) des tribunes.',
                'icone' => 'bi-binoculars-fill',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],
            self::C_SPECTATEUR_FIDELE => [
                'nom' => 'Top fan',
                'description' => '50 venues en spectateur(rice). Une légende du club.',
                'icone' => 'bi-trophy-fill',
                'axe' => self::AXE_BENEVOLAT,
                'par_saison' => false,
            ],

            // ============ AXE D — PERFORMANCE EMPLOYÉ ============
            self::D_FIRST_JOB_MISSION => [
                'nom' => 'Pro débutant',
                'description' => 'Première mission accomplie dans le cadre du poste.',
                'icone' => 'bi-briefcase',
                'axe' => self::AXE_EMPLOYE,
                'par_saison' => false,
            ],
            self::D_JOB_10 => [
                'nom' => 'Actif au poste',
                'description' => '10 missions accomplies dans le cadre du poste rémunéré.',
                'icone' => 'bi-briefcase-fill',
                'axe' => self::AXE_EMPLOYE,
                'par_saison' => true,
            ],
            self::D_JOB_30 => [
                'nom' => 'Pilier du staff',
                'description' => '30 missions au poste dans la saison. Le club tourne grâce à toi.',
                'icone' => 'bi-building-fill',
                'axe' => self::AXE_EMPLOYE,
                'par_saison' => true,
            ],
            self::D_JOB_50 => [
                'nom' => 'Salarié dévoué',
                'description' => '50 missions au poste dans la saison.',
                'icone' => 'bi-gem',
                'axe' => self::AXE_EMPLOYE,
                'par_saison' => true,
            ],
            self::D_JOB_100 => [
                'nom' => 'Cheville ouvrière',
                'description' => '100 missions au poste cumulées. Indispensable.',
                'icone' => 'bi-tools',
                'axe' => self::AXE_EMPLOYE,
                'par_saison' => false,
            ],
            self::D_EMPLOYE_DU_MOIS => [
                'nom' => 'Employé du mois',
                'description' => 'Distinction attribuée par le président — investissement remarquable sur le mois.',
                'icone' => 'bi-trophy-fill',
                'axe' => self::AXE_EMPLOYE,
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
