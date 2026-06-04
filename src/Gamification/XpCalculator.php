<?php

namespace App\Gamification;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\Mission;
use App\Entity\Sport\Presence;
use App\Repository\Sport\MissionRepository;
use App\Repository\Sport\PresenceRepository;

/**
 * XpCalculator — calcule l'XP d'un joueur à partir de ses présences.
 *
 * BARÈME (centralisé ici pour pouvoir tuner facilement) :
 *   - Présent à une séance        : +10 XP
 *   - Présent à une rencontre     : +25 XP
 *   - Absent avec motif (excusé)  :   0 XP (pas pénalisé)
 *   - Absent sans motif           :  -5 XP (pénalité légère pour discipline)
 *   - Bonus série 5 séances       : +20 XP (déclenché 1 fois par streak)
 *   - Bonus série 10 séances      : +50 XP (déclenché 1 fois par streak)
 *
 * L'XP est calculé à la volée depuis la table Presence (single source of
 * truth). Pas de stockage = pas de désynchronisation possible. Si on change
 * le barème, tout le monde voit son XP changer immédiatement.
 *
 * Performances : pour un joueur lambda (< 200 présences), le calcul prend
 * quelques ms. Si on dépasse 1000 joueurs très actifs, on optimisera avec
 * un cache Redis ou des compteurs matérialisés (V2).
 */
class XpCalculator
{
    public const XP_SEANCE_PRESENT       = 10;
    public const XP_RENCONTRE_PRESENT    = 25;
    public const XP_ABSENT_SANS_MOTIF    = -5;
    public const XP_BONUS_STREAK_5       = 20;
    public const XP_BONUS_STREAK_10      = 50;

    /**
     * Barème XP par type de Mission (axe C bénévolat).
     * Calé sur l'engagement requis : "vrai service" > "engagement" > "soutien".
     */
    private const XP_PAR_TYPE_MISSION = [
        Mission::TYPE_TENUE_TABLE     => 25,
        Mission::TYPE_ARBITRAGE       => 25,
        Mission::TYPE_BUVETTE         => 25,
        Mission::TYPE_ENCADREMENT     => 25,
        Mission::TYPE_AG              => 15,
        Mission::TYPE_FORMATION       => 15,
        Mission::TYPE_COMMUNICATION   => 15,
        Mission::TYPE_EVENEMENT       => 10,
        Mission::TYPE_DON             => 10,
        Mission::TYPE_AUTRE           => 10,
    ];

    public function __construct(
        private readonly PresenceRepository $presenceRepository,
        private readonly MissionRepository $missionRepository,
    ) {}

    /**
     * XP total cumulé d'un joueur sur toute sa carrière (toutes saisons).
     */
    public function xpTotal(Joueur $joueur): int
    {
        return $this->xpDansPeriode($joueur, null, null);
    }

    /**
     * XP gagné par un joueur sur une saison donnée (ou la saison courante
     * si saison=null).
     */
    public function xpSaison(Joueur $joueur, ?string $saison = null): int
    {
        [$debut, $fin] = $this->bornesSaison($saison);
        return $this->xpDansPeriode($joueur, $debut, $fin);
    }

    /**
     * Détail de calcul pour affichage UI : breakdown par source.
     *
     * @return array{
     *   xp_total: int,
     *   nb_seances_presentes: int,
     *   nb_seances_absent_excuse: int,
     *   nb_seances_absent_non_excuse: int,
     *   nb_rencontres_presentes: int,
     *   nb_bonus_streak_5: int,
     *   nb_bonus_streak_10: int,
     *   streak_actuel: int,
     * }
     */
    public function detailsSaison(Joueur $joueur, ?string $saison = null): array
    {
        [$debut, $fin] = $this->bornesSaison($saison);
        $presences = $this->presencesDansPeriode($joueur, $debut, $fin);

        $stats = [
            'xp_total'                     => 0,
            'nb_seances_presentes'         => 0,
            'nb_seances_absent_excuse'     => 0,
            'nb_seances_absent_non_excuse' => 0,
            'nb_rencontres_presentes'      => 0,
            'nb_bonus_streak_5'            => 0,
            'nb_bonus_streak_10'           => 0,
            'streak_actuel'                => 0,
        ];

        // ======== Comptage présences ========
        foreach ($presences as $p) {
            $estSeance = $p->getSeance() !== null;
            $estRencontre = $p->getRencontre() !== null;

            if ($p->isPresent()) {
                if ($estSeance) {
                    $stats['nb_seances_presentes']++;
                    $stats['xp_total'] += self::XP_SEANCE_PRESENT;
                } elseif ($estRencontre) {
                    $stats['nb_rencontres_presentes']++;
                    $stats['xp_total'] += self::XP_RENCONTRE_PRESENT;
                }
            } else {
                // Absent : excusé si motif renseigné
                if ($estSeance) {
                    if ($p->getMotifAbsence() !== null && trim($p->getMotifAbsence()) !== '') {
                        $stats['nb_seances_absent_excuse']++;
                        // 0 XP, on ne pénalise pas
                    } else {
                        $stats['nb_seances_absent_non_excuse']++;
                        $stats['xp_total'] += self::XP_ABSENT_SANS_MOTIF;
                    }
                }
            }
        }

        // ======== Calcul des séries (streaks) ========
        // On compte les bonus streak séances : à chaque série de 5 séances
        // PRÉSENTES consécutives, +20 XP. À chaque série de 10, +50 supplémentaires.
        $serieEnCours = 0;
        $serieMax     = 0;
        foreach ($presences as $p) {
            if ($p->getSeance() === null) {
                continue; // Streak basé uniquement sur séances, pas rencontres
            }
            if ($p->isPresent()) {
                $serieEnCours++;
                $serieMax = max($serieMax, $serieEnCours);
                // Bonus déclenché EXACTEMENT à 5 et 10 (puis 15, 20 si on étend)
                if ($serieEnCours === 5) {
                    $stats['nb_bonus_streak_5']++;
                    $stats['xp_total'] += self::XP_BONUS_STREAK_5;
                }
                if ($serieEnCours === 10) {
                    $stats['nb_bonus_streak_10']++;
                    $stats['xp_total'] += self::XP_BONUS_STREAK_10;
                }
            } else {
                $serieEnCours = 0; // une absence casse la série
            }
        }

        // Le streak "actuel" = série en cours à la dernière séance
        $stats['streak_actuel'] = $serieEnCours;

        // ======== XP des Missions (axe C bénévolat) ========
        $missions = $this->missionRepository->pourJoueurDansPeriode($joueur, $debut, $fin);
        $stats['nb_missions'] = count($missions);
        $stats['xp_missions'] = 0;
        foreach ($missions as $m) {
            $xp = self::XP_PAR_TYPE_MISSION[$m->getType()] ?? 10;
            $stats['xp_missions'] += $xp;
            $stats['xp_total']    += $xp;
        }

        // XP ne peut jamais être négatif (sinon affichage moche)
        $stats['xp_total'] = max(0, $stats['xp_total']);

        return $stats;
    }

    /**
     * Bornes [début, fin] d'une saison sportive française (sept → juin).
     * Saison null = saison courante.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function bornesSaison(?string $saison = null): array
    {
        if ($saison === null) {
            $now = new \DateTimeImmutable();
            $mois = (int) $now->format('n');
            $anneeDebut = $mois >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        } else {
            // Format "YYYY-YYYY"
            $anneeDebut = (int) substr($saison, 0, 4);
        }

        $debut = new \DateTimeImmutable($anneeDebut . '-09-01 00:00:00');
        $fin   = new \DateTimeImmutable(($anneeDebut + 1) . '-06-30 23:59:59');
        return [$debut, $fin];
    }

    /**
     * @return Presence[]
     */
    private function presencesDansPeriode(
        Joueur $joueur,
        ?\DateTimeImmutable $debut,
        ?\DateTimeImmutable $fin,
    ): array {
        $toutes = $this->presenceRepository->pourJoueur($joueur);
        if ($debut === null && $fin === null) {
            return $toutes;
        }
        return array_values(array_filter($toutes, function (Presence $p) use ($debut, $fin) {
            $date = $p->getSeance()?->getDate() ?? $p->getRencontre()?->getDate();
            if ($date === null) {
                return false;
            }
            if ($debut !== null && $date < $debut) return false;
            if ($fin !== null && $date > $fin) return false;
            return true;
        }));
    }

    /**
     * Implémentation interne du calcul d'XP sur une période.
     * Réutilise la même logique que detailsSaison() mais retourne juste l'int.
     */
    private function xpDansPeriode(
        Joueur $joueur,
        ?\DateTimeImmutable $debut,
        ?\DateTimeImmutable $fin,
    ): int {
        // On délègue à detailsSaison qui fait tout le boulot
        // Pour la période "tout", on simule en passant saison=null + filtrage manuel
        $presences = $this->presencesDansPeriode($joueur, $debut, $fin);

        $xp = 0;
        $serieEnCours = 0;
        foreach ($presences as $p) {
            if ($p->isPresent()) {
                if ($p->getSeance() !== null) {
                    $xp += self::XP_SEANCE_PRESENT;
                    $serieEnCours++;
                    if ($serieEnCours === 5)  $xp += self::XP_BONUS_STREAK_5;
                    if ($serieEnCours === 10) $xp += self::XP_BONUS_STREAK_10;
                } elseif ($p->getRencontre() !== null) {
                    $xp += self::XP_RENCONTRE_PRESENT;
                }
            } else {
                if ($p->getSeance() !== null) {
                    if ($p->getMotifAbsence() === null || trim($p->getMotifAbsence()) === '') {
                        $xp += self::XP_ABSENT_SANS_MOTIF;
                    }
                    $serieEnCours = 0;
                }
            }
        }

        // Ajout XP missions (axe C bénévolat)
        $missions = $this->missionRepository->pourJoueurDansPeriode($joueur, $debut, $fin);
        foreach ($missions as $m) {
            $xp += self::XP_PAR_TYPE_MISSION[$m->getType()] ?? 10;
        }

        return max(0, $xp);
    }
}
