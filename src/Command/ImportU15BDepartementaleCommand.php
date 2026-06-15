<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\EvaluationFfbb;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les 14 rencontres de l'équipe U15B Départementale 2025-2026
 * avec les statistiques individuelles extraites des PDFs FFBB officiels.
 *
 * IDEMPOTENT : sûr à relancer plusieurs fois. Utilise les clés FFBB
 * (equipe + division + numeroMatch) pour détecter les doublons.
 *
 * Sources : /ressource/rencontre u15b/*.pdf  — PDFs FFBB résumé officiel
 *           Extraction manuelle par Clavel + vérification pts = p3×3 + p2×2 + lf
 *
 * Colonnes evals : [num_maillot, nom_complet, starter, minutes_jouees, pts,
 *                   p3_reussis, p2int_reussis, p2ext_reussis, lf_reussis, fautes]
 *
 * Note : tirs2ptReussis = p2int + p2ext (la FFBB sépare intérieur/extérieur
 *         mais l'entité les combine en un seul champ).
 */
#[AsCommand(
    name: 'app:import-u15b-departementale',
    description: 'Import U15B Départementale 2025-2026 — 14 rencontres + stats FFBB (idempotent).',
)]
class ImportU15BDepartementaleCommand extends Command
{
    // =========================================================================
    // CONSTANTES MÉTIER
    // =========================================================================

    private const SAISON       = '2025-2026';
    private const DIVISION_P1  = 'DFU15';   // Poule 1 (phase régulière)
    private const DIVISION_P2  = 'DFU15-P2'; // Poule 2 (phase finale)
    private const EQUIPE_NOM   = 'U15B Dép. 2025-2026';
    private const EQUIPE_CAT   = 'U15';
    private const EQUIPE_NIVO  = 'Départemental Féminin';

    // =========================================================================
    // MAPPING NOM COMPLET → (nom, prenom) pour création Joueur
    // Toutes les 22 joueuses uniques de la saison.
    // Raison d'un map statique : certains noms composés (BEN SALAH, EL HAMDAOUI)
    // ne peuvent pas être découpés par un simple split sur le dernier mot.
    // =========================================================================

    /** @var array<string, array{nom: string, prenom: string}> */
    private const JOUEURS_MAP = [
        'EL HAMDAOUI Aya'         => ['nom' => 'EL HAMDAOUI',    'prenom' => 'Aya'],
        'GUELFAT Maïssa'          => ['nom' => 'GUELFAT',         'prenom' => 'Maïssa'],
        'DIDOUH Malak'            => ['nom' => 'DIDOUH',          'prenom' => 'Malak'],
        'BEN SALAH Séphora'       => ['nom' => 'BEN SALAH',       'prenom' => 'Séphora'],
        'RHAMMOUZ Kenza'          => ['nom' => 'RHAMMOUZ',        'prenom' => 'Kenza'],
        'EZZARRARI Kawthar'       => ['nom' => 'EZZARRARI',       'prenom' => 'Kawthar'],
        'DIARRA Aïssatou'         => ['nom' => 'DIARRA',          'prenom' => 'Aïssatou'],
        'AMIRAT Inaya'            => ['nom' => 'AMIRAT',          'prenom' => 'Inaya'],
        'AMIRAT Maïssa'           => ['nom' => 'AMIRAT',          'prenom' => 'Maïssa'],
        'BAHI Sara'               => ['nom' => 'BAHI',            'prenom' => 'Sara'],
        'GOMBERT Yaëlle'          => ['nom' => 'GOMBERT',         'prenom' => 'Yaëlle'],
        'KANZA LOUKOULA Roxane'   => ['nom' => 'KANZA LOUKOULA',  'prenom' => 'Roxane'],
        'BALDE Safiatou'          => ['nom' => 'BALDE',           'prenom' => 'Safiatou'],
        'BOUABDELLI Nadia'        => ['nom' => 'BOUABDELLI',      'prenom' => 'Nadia'],
        'FECHTALA Asma'           => ['nom' => 'FECHTALA',        'prenom' => 'Asma'],
        'EL MOUAHHIDE Soumaya'    => ['nom' => 'EL MOUAHHIDE',    'prenom' => 'Soumaya'],
        'BOUDHAN Meissa'          => ['nom' => 'BOUDHAN',         'prenom' => 'Meissa'],
        'LAARAYBI Jenna'          => ['nom' => 'LAARAYBI',        'prenom' => 'Jenna'],
        'KAMARA DUKURI Fatoumata' => ['nom' => 'KAMARA DUKURI',  'prenom' => 'Fatoumata'],
        'Jardani Rita'            => ['nom' => 'JARDANI',          'prenom' => 'Rita'],
        'FOUILAH Safa'            => ['nom' => 'FOUILAH',         'prenom' => 'Safa'],
        'LEITE Yana'              => ['nom' => 'LEITE',           'prenom' => 'Yana'],
    ];

    // =========================================================================
    // DONNÉES MATCHS — extraites des PDFs FFBB résumé officiels
    //
    // Chaque eval : [num_maillot, nom_complet, starter, min_joués,
    //                pts, 3pt_réussis, 2int_réussis, 2ext_réussis, lf_réussis, fautes]
    //
    // Vérification : pts === p3×3 + (p2int+p2ext)×2 + lf  ← testé pour chaque ligne
    // =========================================================================

    private const MATCHES = [
        // ── POULE 1 — PHASE RÉGULIÈRE ────────────────────────────────────────

        /* Rencontre 7 — 11/10/2025 — MABB 154 – Villers-Bretonneux 8 */
        [
            'ffbbNum'      => '7',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-10-11 12:00:00',
            'adversaire'   => 'BASKET BALL VILLERS BRETONNEUX',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 154,
            'scoreAdverse' => 8,
            'evals' => [
                //  N°  Nom                       Tit    Min  Pts  3pt p2in p2ex  LF   F
                [  5, 'EL HAMDAOUI Aya',          true,  23,  19,  1,   8,   0,   0,   4],
                [  6, 'GUELFAT Maïssa',            true,  25,  38,  5,  10,   1,   1,   2],
                [  7, 'DIDOUH Malak',              true,  25,   8,  0,   4,   0,   0,   0],
                [ 10, 'BEN SALAH Séphora',         false, 22,  17,  0,   8,   0,   1,   0],
                [ 11, 'RHAMMOUZ Kenza',            true,  27,  24,  1,  10,   0,   1,   2],
                [ 12, 'EZZARRARI Kawthar',         false, 26,  22,  0,  11,   0,   0,   2],
                [ 13, 'DIARRA Aïssatou',           true,  30,  24,  1,  10,   0,   1,   2],
                [ 15, 'AMIRAT Inaya',              false, 17,   2,  0,   1,   0,   0,   1],
            ],
        ],

        /* Rencontre 15 — 15/11/2025 — CA Péronne 71 – MABB 77 (déplacement) */
        [
            'ffbbNum'      => '15',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-11-15 13:00:00',
            'adversaire'   => 'CA PERONNE BASKET BALL',
            'lieu'         => 'PERONNE',
            'domicile'     => false,
            'scoreEquipe'  => 77,
            'scoreAdverse' => 71,
            'evals' => [
                [  4, 'EL HAMDAOUI Aya',           true,  29,   8,  0,   4,   0,   0,   5],
                [  5, 'DIARRA Aïssatou',            true,  33,   3,  0,   1,   0,   1,   0],
                [  7, 'DIDOUH Malak',               false, 32,   5,  0,   2,   0,   1,   3],
                [  8, 'GUELFAT Maïssa',             true,  33,  15,  2,   3,   1,   1,   1],
                [ 10, 'BEN SALAH Séphora',          true,  33,   4,  0,   2,   0,   0,   2],
                [ 11, 'RHAMMOUZ Kenza',             true,  33,  17,  1,   7,   0,   0,   3],
                [ 12, 'EZZARRARI Kawthar',          false, 28,   0,  0,   0,   0,   0,   5],
                [ 13, 'BAHI Sara',                  false, 32,  25,  0,  12,   0,   1,   1],
            ],
        ],

        /* Rencontre 20 — 22/11/2025 — Boves 50 – MABB 74 (déplacement) */
        [
            'ffbbNum'      => '20',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-11-22 12:00:00',
            'adversaire'   => 'UNION SPORTIVE DE BOVES BASKET-BALL',
            'lieu'         => 'BOVES',
            'domicile'     => false,
            'scoreEquipe'  => 74,
            'scoreAdverse' => 50,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  19,   0,  0,   0,   0,   0,   2],
                [  5, 'EL HAMDAOUI Aya',            true,  21,   4,  0,   2,   0,   0,   3],
                [  7, 'GUELFAT Maïssa',             true,  21,  31,  2,   9,   1,   5,   1],
                [  8, 'AMIRAT Maïssa',              false, 15,   0,  0,   0,   0,   0,   2],
                [  9, 'DIDOUH Malak',               true,  19,   2,  0,   1,   0,   0,   0],
                [ 10, 'BEN SALAH Séphora',          false, 22,   8,  0,   2,   2,   0,   3],
                [ 11, 'RHAMMOUZ Kenza',             true,  21,  11,  0,   5,   0,   1,   4],
                [ 13, 'BAHI Sara',                  false, 24,  12,  0,   5,   1,   0,   0],
                [ 14, 'BALDE Safiatou',             false, 19,   2,  0,   1,   0,   0,   2],
                [ 15, 'BOUABDELLI Nadia',           false, 13,   4,  0,   0,   2,   0,   3],
            ],
        ],

        /* Rencontre 21 — 29/11/2025 — MABB 128 – Montdidérien 21 (domicile) */
        [
            'ffbbNum'      => '21',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-11-29 17:00:00',
            'adversaire'   => 'BASKET BALL MONTDIDERIEN',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 128,
            'scoreAdverse' => 21,
            'evals' => [
                [  5, 'EL HAMDAOUI Aya',            true,  26,  26,  0,  12,   1,   0,   3],
                [  8, 'GUELFAT Maïssa',             true,  26,  25,  3,   7,   0,   2,   2],
                [  9, 'GOMBERT Yaëlle',             true,  22,  13,  0,   6,   0,   1,   0],
                [ 11, 'RHAMMOUZ Kenza',             true,  23,  28,  1,  12,   0,   1,   2],
                [ 13, 'BAHI Sara',                  true,  27,  34,  4,  11,   0,   0,   1],
                [ 14, 'AMIRAT Maïssa',              false, 21,   0,  0,   0,   0,   0,   0],
                [ 15, 'BOUABDELLI Nadia',           false, 24,   2,  0,   1,   0,   0,   1],
            ],
        ],

        /* Rencontre 26 — 06/12/2025 — Beauchamps 53 – MABB 105 (déplacement) */
        [
            'ffbbNum'      => '26',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-12-06 15:00:00',
            'adversaire'   => 'US BEAUCHAMPS',
            'lieu'         => 'BEAUCHAMPS',
            'domicile'     => false,
            'scoreEquipe'  => 105,
            'scoreAdverse' => 53,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  21,   9,  1,   3,   0,   0,   1],
                [  5, 'EL HAMDAOUI Aya',            true,  28,  21,  0,   7,   3,   1,   2],
                [  8, 'GUELFAT Maïssa',             false, 28,  28,  4,   5,   2,   2,   4],
                [  9, 'KANZA LOUKOULA Roxane',      true,  34,  11,  1,   4,   0,   0,   3],
                [ 10, 'BEN SALAH Séphora',          true,  28,  10,  0,   4,   1,   0,   3],
                [ 11, 'RHAMMOUZ Kenza',             false, 28,  24,  0,  10,   2,   0,   1],
                [ 12, 'AMIRAT Maïssa',              true,  30,   2,  0,   0,   0,   2,   2],
            ],
        ],

        /* Rencontre 31 — 13/12/2025 — ESC Longueau 32 – MABB 93 (déplacement) */
        [
            'ffbbNum'      => '31',
            'division'     => self::DIVISION_P1,
            'date'         => '2025-12-13 14:00:00',
            'adversaire'   => 'ESC LONGUEAU AMIENS MSBB',
            'lieu'         => 'LONGUEAU',
            'domicile'     => false,
            'scoreEquipe'  => 93,
            'scoreAdverse' => 32,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            false, 12,   2,  0,   0,   1,   0,   1],
                [  5, 'AMIRAT Inaya',               true,  26,   8,  0,   3,   1,   0,   1],
                [  7, 'GUELFAT Maïssa',             true,  32,  26,  2,   8,   2,   0,   1],
                [  9, 'DIDOUH Malak',               true,  28,   4,  0,   1,   1,   0,   0],
                [ 10, 'BEN SALAH Séphora',          false, 13,  18,  2,   6,   0,   0,   0],
                [ 11, 'RHAMMOUZ Kenza',             true,  29,  12,  0,   6,   0,   0,   3],
                [ 12, 'AMIRAT Maïssa',              false, 16,   0,  0,   0,   0,   0,   4],
                [ 13, 'BAHI Sara',                  true,  33,  23,  1,   8,   2,   0,   2],
                [ 14, 'FECHTALA Asma',              false,  7,   0,  0,   0,   0,   0,   1],
            ],
        ],

        /* Rencontre 35 — 10/01/2026 — Villers-Bretonneux 31 – MABB 77 (déplacement) */
        [
            'ffbbNum'      => '35',
            'division'     => self::DIVISION_P1,
            'date'         => '2026-01-10 14:00:00',
            'adversaire'   => 'BASKET BALL VILLERS BRETONNEUX',
            'lieu'         => 'VILLERS-BRETONNEUX',
            'domicile'     => false,
            'scoreEquipe'  => 77,
            'scoreAdverse' => 31,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  23,   6,  0,   3,   0,   0,   2],
                [  5, 'AMIRAT Inaya',               false, 12,   8,  0,   4,   0,   0,   3],
                [  8, 'EL HAMDAOUI Aya',            false, 16,   8,  0,   4,   0,   0,   0],
                [  9, 'DIDOUH Malak',               true,  34,  13,  0,   5,   1,   1,   1],
                [ 10, 'BEN SALAH Séphora',          true,  28,  15,  0,   6,   1,   1,   4],
                [ 11, 'RHAMMOUZ Kenza',             false, 14,  13,  1,   5,   0,   0,   1],
                [ 12, 'AMIRAT Maïssa',              true,  23,   8,  0,   3,   1,   0,   0],
                [ 13, 'EL MOUAHHIDE Soumaya',       false, 20,   2,  0,   1,   0,   0,   0],
                [ 15, 'BOUDHAN Meissa',             true,  26,   4,  0,   2,   0,   0,   1],
            ],
        ],

        /* Rencontre 43 — 24/01/2026 — MABB 75 – CA Péronne 41 (domicile) */
        [
            'ffbbNum'      => '43',
            'division'     => self::DIVISION_P1,
            'date'         => '2026-01-24 12:00:00',
            'adversaire'   => 'CA PERONNE BASKET BALL',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 75,
            'scoreAdverse' => 41,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  23,   8,  0,   3,   0,   2,   4],
                [  5, 'EL HAMDAOUI Aya',            true,  17,   4,  0,   2,   0,   0,   2],
                [  6, 'GUELFAT Maïssa',             false, 19,  10,  0,   4,   0,   2,   3],
                [  9, 'DIDOUH Malak',               true,  26,   9,  0,   3,   1,   1,   2],
                [ 10, 'BEN SALAH Séphora',          true,  25,   4,  0,   1,   1,   0,   4],
                [ 11, 'RHAMMOUZ Kenza',             false, 21,   9,  0,   4,   0,   1,   2],
                [ 12, 'AMIRAT Maïssa',              false, 19,   0,  0,   0,   0,   0,   2],
                [ 13, 'BAHI Sara',                  true,  32,  27,  1,  11,   1,   0,   2],
                [ 15, 'AMIRAT Inaya',               false, 14,   4,  0,   1,   1,   0,   1],
            ],
        ],

        /* Rencontre 48 — 31/01/2026 — MABB 68 – Boves 35 (domicile) */
        [
            'ffbbNum'      => '48',
            'division'     => self::DIVISION_P1,
            'date'         => '2026-01-31 17:00:00',
            'adversaire'   => 'UNION SPORTIVE DE BOVES BASKET-BALL',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 68,
            'scoreAdverse' => 35,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  24,   2,  0,   1,   0,   0,   1],
                [  5, 'EL HAMDAOUI Aya',            true,  24,   4,  0,   2,   0,   0,   4],
                [  6, 'DIDOUH Malak',               true,  32,  15,  0,   7,   0,   1,   2],
                [  8, 'KANZA LOUKOULA Roxane',      false, 22,   5,  0,   2,   0,   1,   1],
                [  9, 'BEN SALAH Séphora',          false,  8,   2,  0,   1,   0,   0,   1],
                [ 10, 'LAARAYBI Jenna',             false,  5,   0,  0,   0,   0,   0,   0],
                [ 11, 'RHAMMOUZ Kenza',             true,  24,   3,  0,   1,   0,   1,   5],
                [ 12, 'AMIRAT Maïssa',              false, 23,   2,  0,   0,   0,   2,   2],
                [ 13, 'BAHI Sara',                  true,  34,  35,  2,  14,   0,   1,   4],
            ],
        ],

        /* Rencontre 54 — 14/02/2026 — MABB 92 – Beauchamps 27 (domicile) */
        [
            'ffbbNum'      => '54',
            'division'     => self::DIVISION_P1,
            'date'         => '2026-02-14 17:00:00',
            'adversaire'   => 'US BEAUCHAMPS',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 92,
            'scoreAdverse' => 27,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  14,   5,  1,   1,   0,   0,   3],
                [  5, 'EL HAMDAOUI Aya',            true,  25,  13,  1,   4,   1,   0,   1],
                [  8, 'GUELFAT Maïssa',             false, 25,  29,  1,  12,   1,   0,   2],
                [  9, 'DIDOUH Malak',               true,  19,  10,  0,   4,   0,   2,   1],
                [ 10, 'BEN SALAH Séphora',          true,  22,  12,  0,   5,   1,   0,   1],
                [ 12, 'AMIRAT Maïssa',              true,  23,   2,  0,   1,   0,   0,   3],
                [ 13, 'KANZA LOUKOULA Roxane',      false, 24,   6,  0,   3,   0,   0,   2],
                [ 14, 'RHAMMOUZ Kenza',             false, 19,  13,  0,   6,   0,   1,   3],
                [ 15, 'BOUDHAN Meissa',             false, 23,   2,  0,   1,   0,   0,   2],
            ],
        ],

        // ── POULE 2 — PHASE FINALE ───────────────────────────────────────────

        /* P2-5 — 21/03/2026 — MABB 63 – CA Péronne 70 ← SEULE DÉFAITE */
        [
            'ffbbNum'      => '5',
            'division'     => self::DIVISION_P2,
            'date'         => '2026-03-21 17:00:00',
            'adversaire'   => 'CA PERONNE BASKET BALL',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 63,
            'scoreAdverse' => 70,
            'evals' => [
                [  5, 'KAMARA DUKURI Fatoumata',    true,  39,  30,  0,  10,   2,   6,   4],
                [  7, 'DIDOUH Malak',               true,  39,   4,  0,   2,   0,   0,   1],
                [ 11, 'RHAMMOUZ Kenza',             true,  39,  21,  1,   6,   1,   4,   4],
                [ 12, 'Jardani Rita',               true,  39,   2,  0,   1,   0,   0,   2],
                [ 13, 'BEN SALAH Séphora',          true,  39,   6,  0,   1,   0,   4,   2],
            ],
        ],

        /* P2-8 — 04/04/2026 — Boves 46 – MABB 83 (déplacement) */
        [
            'ffbbNum'      => '8',
            'division'     => self::DIVISION_P2,
            'date'         => '2026-04-04 12:00:00',
            'adversaire'   => 'UNION SPORTIVE DE BOVES BASKET-BALL',
            'lieu'         => 'BOVES',
            'domicile'     => false,
            'scoreEquipe'  => 83,
            'scoreAdverse' => 46,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  21,   7,  0,   3,   0,   1,   3],
                [  5, 'EL HAMDAOUI Aya',            false, 15,   2,  0,   1,   0,   0,   0],
                [  6, 'DIDOUH Malak',               true,  18,  12,  2,   2,   1,   0,   1],
                [  7, 'AMIRAT Maïssa',              false, 22,   4,  0,   1,   0,   2,   3],
                [  8, 'GUELFAT Maïssa',             false, 25,  24,  2,   8,   0,   2,   3],
                [  9, 'KANZA LOUKOULA Roxane',      true,  20,   6,  0,   2,   0,   2,   2],
                [ 10, 'BEN SALAH Séphora',          true,  18,   3,  0,   1,   0,   1,   5],
                [ 13, 'BAHI Sara',                  true,  28,  23,  0,   9,   1,   3,   1],
                [ 14, 'BOUDHAN Meissa',             false, 15,   0,  0,   0,   0,   0,   0],
                [ 15, 'FOUILAH Safa',               false, 15,   2,  0,   1,   0,   0,   2],
            ],
        ],

        /* P2-11 — 02/05/2026 — MABB 85 – ESC Longueau 36 (domicile) */
        [
            'ffbbNum'      => '11',
            'division'     => self::DIVISION_P2,
            'date'         => '2026-05-02 17:00:00',
            'adversaire'   => 'ESC LONGUEAU AMIENS MSBB',
            'lieu'         => 'AMIENS',
            'domicile'     => true,
            'scoreEquipe'  => 85,
            'scoreAdverse' => 36,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  32,  10,  0,   5,   0,   0,   3],
                [  5, 'EL HAMDAOUI Aya',            true,  10,  15,  2,   4,   0,   1,   3],
                [  6, 'DIDOUH Malak',               false,  8,   8,  0,   4,   0,   0,   0],
                [  7, 'KANZA LOUKOULA Roxane',      true,  18,   4,  0,   2,   0,   0,   0],
                [  8, 'Jardani Rita',               false, 16,   4,  0,   2,   0,   0,   0],
                [  9, 'AMIRAT Inaya',               false, 19,   6,  0,   3,   0,   0,   2],
                [ 10, 'BEN SALAH Séphora',          false, 26,   2,  0,   1,   0,   0,   1],
                [ 13, 'BAHI Sara',                  true,  10,  22,  0,  10,   0,   2,   3],
                [ 14, 'RHAMMOUZ Kenza',             true,  30,  14,  0,   6,   0,   2,   4],
                [ 15, 'BOUDHAN Meissa',             false, 20,   0,  0,   0,   0,   0,   0],
            ],
        ],

        /* P2-14 — 09/05/2026 — Villers-Bretonneux 49 – MABB 76 (déplacement) */
        [
            'ffbbNum'      => '14',
            'division'     => self::DIVISION_P2,
            'date'         => '2026-05-09 17:00:00',
            'adversaire'   => 'BASKET BALL VILLERS BRETONNEUX',
            'lieu'         => 'VILLERS-BRETONNEUX',
            'domicile'     => false,
            'scoreEquipe'  => 76,
            'scoreAdverse' => 49,
            'evals' => [
                [  4, 'DIARRA Aïssatou',            true,  22,  11,  0,   5,   0,   1,   4],
                [  6, 'DIDOUH Malak',               true,  19,   6,  0,   3,   0,   0,   4],
                [  7, 'KANZA LOUKOULA Roxane',      false, 24,   8,  0,   2,   2,   0,   1],
                [  8, 'GUELFAT Maïssa',             false, 25,  17,  1,   7,   0,   0,   4],
                [  9, 'AMIRAT Maïssa',              false, 14,   0,  0,   0,   0,   0,   2],
                [ 10, 'BEN SALAH Séphora',          false, 13,   0,  0,   0,   0,   0,   4],
                [ 13, 'BAHI Sara',                  true,  31,  23,  0,  11,   0,   1,   4],
                [ 14, 'RHAMMOUZ Kenza',             true,  25,   6,  0,   3,   0,   0,   5],
                [ 15, 'LEITE Yana',                 true,  23,   5,  1,   1,   0,   0,   4],
            ],
        ],
    ];

    // =========================================================================
    // INJECTION DEPENDENCIES
    // =========================================================================

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    // =========================================================================
    // EXECUTE
    // =========================================================================

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Import U15B Départementale 2025-2026');

        // ── 1. Club ───────────────────────────────────────────────────────────
        $club = $this->em->getRepository(Club::class)->findOneBy(['slug' => 'mabb']);
        if (!$club) {
            $io->error('Club "mabb" introuvable en BDD. Vérifiez que les fixtures de base sont chargées.');
            return Command::FAILURE;
        }
        $io->text(sprintf('✔ Club trouvé : %s', $club->getNom()));

        // ── 2. Équipe ─────────────────────────────────────────────────────────
        $equipe = $this->findOrCreateEquipe($club);
        $io->text(sprintf('✔ Équipe : %s', $equipe->getNom()));

        // ── 3. Phase 1 : Rencontres ───────────────────────────────────────────
        $io->section('Phase 1 — Création des rencontres');

        /** @var array<string, Rencontre> $rencontresIndexed clé = "division:ffbbNum" */
        $rencontresIndexed = [];
        $nbRencNew = 0;
        $nbRencUpd = 0;

        foreach (self::MATCHES as $matchData) {
            $key = $matchData['division'] . ':' . $matchData['ffbbNum'];

            // Lookup idempotent : equipe + division + numeroMatch = identifiant FFBB stable
            $rencontre = $this->em->getRepository(Rencontre::class)->findOneBy([
                'equipe'      => $equipe,
                'division'    => $matchData['division'],
                'numeroMatch' => $matchData['ffbbNum'],
            ]);

            $isNew = ($rencontre === null);
            if ($isNew) {
                $rencontre = new Rencontre();
                $rencontre->setClub($club);
                $rencontre->setEquipe($equipe);
                $nbRencNew++;
            } else {
                $nbRencUpd++;
            }

            // Hydratation complète (écrase même si déjà en BDD → mise à jour)
            $rencontre
                ->setTypeRencontre(Rencontre::TYPE_OFFICIEL)
                ->setNumeroMatch($matchData['ffbbNum'])
                ->setSaison(self::SAISON)
                ->setDivision($matchData['division'])
                ->setAdversaire($matchData['adversaire'])
                ->setDate(new \DateTimeImmutable($matchData['date']))
                ->setLieu($matchData['lieu'])
                ->setDomicile($matchData['domicile'])
                ->setScoreEquipe($matchData['scoreEquipe'])
                ->setScoreAdverse($matchData['scoreAdverse'])
                ->setStatut(Rencontre::STATUT_VALIDE)
                ->setNbPeriodes(4)               // U15 : 4 × 8 min
                ->setDureePeriodeMinutes(8)
            ;

            if ($isNew) {
                $this->em->persist($rencontre);
            }

            $rencontresIndexed[$key] = $rencontre;

            $score = sprintf('%d – %d', $matchData['scoreEquipe'], $matchData['scoreAdverse']);
            $icone = $matchData['scoreEquipe'] > $matchData['scoreAdverse'] ? '🏆' : '❌';
            $io->text(sprintf(
                '  %s [%s #%s] %s vs %s (%s)',
                $icone,
                $matchData['division'],
                $matchData['ffbbNum'],
                $isNew ? '<info>CRÉÉ</info>' : '<comment>MIS À JOUR</comment>',
                $matchData['adversaire'],
                $score,
            ));
        }

        // Flush Phase 1 : tous les Rencontre ont besoin d'un ID avant l'insertion des evals
        $this->em->flush();
        $io->text(sprintf('  → %d créées, %d mises à jour', $nbRencNew, $nbRencUpd));

        // ── 4. Phase 2 : Joueurs + EvaluationFfbb ────────────────────────────
        $io->section('Phase 2 — Joueurs & stats individuelles');

        /** @var array<string, Joueur> $joueursCache clé = "NOM_COMPLET" */
        $joueursCache = [];
        $nbJouNew = 0;
        $nbEvalNew = 0;
        $nbEvalUpd = 0;

        foreach (self::MATCHES as $matchData) {
            $key       = $matchData['division'] . ':' . $matchData['ffbbNum'];
            $rencontre = $rencontresIndexed[$key];

            foreach ($matchData['evals'] as $evalData) {
                [$numMaillot, $nomComplet, $starter, $minutes,
                 $pts, $p3, $p2int, $p2ext, $lf, $fautes] = $evalData;

                // ── Joueur ───────────────────────────────────────────────────
                if (!isset($joueursCache[$nomComplet])) {
                    $joueursCache[$nomComplet] = $this->findOrCreateJoueur($nomComplet, $club, $equipe, $nbJouNew);
                }
                $joueur = $joueursCache[$nomComplet];

                // ── EvaluationFfbb (upsert sur la contrainte unique) ─────────
                $eval = $this->em->getRepository(EvaluationFfbb::class)->findOneBy([
                    'rencontre'    => $rencontre,
                    'numeroMaillot'=> $numMaillot,
                ]);

                $isNew = ($eval === null);
                if ($isNew) {
                    $eval = new EvaluationFfbb();
                    $eval->setRencontre($rencontre);
                    $nbEvalNew++;
                } else {
                    $nbEvalUpd++;
                }

                $eval
                    ->setJoueur($joueur)
                    ->setNumeroMaillot($numMaillot)
                    ->setNomComplet($nomComplet)
                    ->setEstStarter($starter)
                    ->setMinutesJouees($minutes)
                    ->setPoints($pts)
                    ->setTirs3ptReussis($p3)
                    ->setTirs2ptReussis($p2int + $p2ext)  // FFBB sépare int/ext, entité combine
                    ->setLancersReussis($lf)
                    ->setFautesCommises($fautes)
                ;

                if ($isNew) {
                    $this->em->persist($eval);
                }
            }
        }

        $this->em->flush();

        // ── 5. Récap ─────────────────────────────────────────────────────────
        $io->success(sprintf(
            'Import terminé — %d rencontres (%d créées, %d mises à jour) · %d joueuses (%d nouvelles) · %d évaluations (%d créées, %d mises à jour)',
            count(self::MATCHES),
            $nbRencNew, $nbRencUpd,
            count($joueursCache),
            $nbJouNew,
            $nbEvalNew + $nbEvalUpd,
            $nbEvalNew, $nbEvalUpd,
        ));

        // Bilan victoires / défaites
        $victoires = 0; $defaites = 0;
        foreach (self::MATCHES as $m) {
            $m['scoreEquipe'] > $m['scoreAdverse'] ? $victoires++ : $defaites++;
        }
        $io->text(sprintf('  Bilan saison : <info>%d victoires</info> / <comment>%d défaite(s)</comment>', $victoires, $defaites));

        return Command::SUCCESS;
    }

    // =========================================================================
    // HELPERS PRIVÉS
    // =========================================================================

    /**
     * Trouve ou crée l'équipe U15B Départementale 2025-2026.
     */
    private function findOrCreateEquipe(Club $club): Equipe
    {
        $equipe = $this->em->getRepository(Equipe::class)->findOneBy([
            'nom'   => self::EQUIPE_NOM,
            'saison'=> self::SAISON,
            'club'  => $club,
        ]);

        if ($equipe === null) {
            $equipe = (new Equipe())
                ->setClub($club)
                ->setNom(self::EQUIPE_NOM)
                ->setCategorie(self::EQUIPE_CAT)
                ->setSaison(self::SAISON)
                ->setNiveau(self::EQUIPE_NIVO)
                ->setIsActive(true)
            ;
            $this->em->persist($equipe);
            $this->em->flush(); // Flush immédiat : les rencontres ont besoin de l'ID equipe
        }

        return $equipe;
    }

    /**
     * Trouve ou crée un Joueur à partir du nomComplet FFBB.
     *
     * Logique d'identification : (nom, prenom, club).
     * On s'appuie sur JOUEURS_MAP pour décomposer les noms composés (BEN SALAH,
     * EL HAMDAOUI…) qu'un simple split sur le dernier espace ne gèrerait pas.
     *
     * @param int $nbNew  compteur créations, modifié par référence
     */
    private function findOrCreateJoueur(
        string $nomComplet,
        Club $club,
        Equipe $equipe,
        int &$nbNew,
    ): Joueur {
        if (!isset(self::JOUEURS_MAP[$nomComplet])) {
            // Fallback sécurisé si un nouveau nom apparaît sans mapping
            // On split sur le dernier espace (fonctionne pour les noms simples)
            $parts   = explode(' ', $nomComplet, 2);
            $nomStr  = strtoupper($parts[0]);
            $prenomStr = $parts[1] ?? $nomStr;
        } else {
            $nomStr    = self::JOUEURS_MAP[$nomComplet]['nom'];
            $prenomStr = self::JOUEURS_MAP[$nomComplet]['prenom'];
        }

        $joueur = $this->em->getRepository(Joueur::class)->findOneBy([
            'nom'    => $nomStr,
            'prenom' => $prenomStr,
            'club'   => $club,
        ]);

        if ($joueur === null) {
            $joueur = (new Joueur())
                ->setNom($nomStr)
                ->setPrenom($prenomStr)
                ->setClub($club)
                ->setEquipe($equipe)
            ;
            $this->em->persist($joueur);
            $nbNew++;
        }

        return $joueur;
    }
}
