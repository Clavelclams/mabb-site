<?php

namespace App\Gamification;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\JoueurBadge;
use App\Entity\Sport\Presence;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\PresenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BadgeChecker — vérifie l'éligibilité aux badges et les persiste.
 *
 * Une seule méthode publique principale : `syncBadges(Joueur)`. À appeler
 * après chaque création/modif/suppression de Presence (cf. PresenceSubscriber).
 *
 * Le checker est idempotent : appelé 10 fois sur le même joueur sans nouvelles
 * présences, il ne crée aucun badge en double. Il vérifie avant chaque
 * persist que le code+saison n'existe pas déjà.
 *
 * Pattern de conception : grand switch sur le code badge. Si on dépasse
 * 30 badges, on bascule sur strategy pattern (1 classe par badge), mais
 * pour V1 (11 badges), le switch reste lisible et tient sur un écran.
 */
class BadgeChecker
{
    public function __construct(
        private readonly PresenceRepository $presenceRepository,
        private readonly JoueurBadgeRepository $badgeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Recalcule tous les badges éligibles pour un joueur sur la saison
     * courante et persiste les nouveaux.
     *
     * @return JoueurBadge[] liste des badges nouvellement débloqués
     */
    public function syncBadges(Joueur $joueur, ?string $saison = null): array
    {
        $saison ??= $this->saisonCourante();
        $codesDejaDebloque = $this->badgeRepository->codesBadgesPourJoueur($joueur, $saison);
        $nouveaux = [];

        // Cache : on charge UNE fois les présences pour ne pas refaire des
        // queries dans chaque test de badge. Critique pour la perf.
        $presences = $this->presenceRepository->pourJoueur($joueur);

        foreach (BadgeCatalog::all() as $code => $def) {
            // Skip si déjà débloqué (pour cette saison ou hors-saison)
            if (in_array($code, $codesDejaDebloque, true)) {
                continue;
            }

            if ($this->estEligible($code, $joueur, $presences, $saison)) {
                $saisonBadge = $def['par_saison'] ? $saison : null;
                $badge = new JoueurBadge($joueur, $code, $saisonBadge);
                $this->em->persist($badge);
                $nouveaux[] = $badge;
            }
        }

        if (!empty($nouveaux)) {
            $this->em->flush();
        }

        return $nouveaux;
    }

    /**
     * Vérifie l'éligibilité du joueur à un badge précis.
     * Switch sur le code, chaque case appelle un helper privé typé.
     *
     * @param Presence[] $presences présences déjà chargées (cache)
     */
    private function estEligible(
        string $code,
        Joueur $joueur,
        array $presences,
        string $saisonCourante,
    ): bool {
        return match ($code) {
            BadgeCatalog::A_FIRST_TRAINING => $this->aDejaUneSeancePresente($presences),
            BadgeCatalog::A_FIRST_MATCH    => $this->aDejaUneRencontrePresente($presences),
            BadgeCatalog::A_STREAK_5       => $this->streakSeancesMax($presences, $saisonCourante) >= 5,
            BadgeCatalog::A_STREAK_10      => $this->streakSeancesMax($presences, $saisonCourante) >= 10,
            BadgeCatalog::A_STREAK_20      => $this->streakSeancesMax($presences, $saisonCourante) >= 20,
            BadgeCatalog::A_MONTH_100      => $this->aUnMoisCalendaireA100Pct($presences, $saisonCourante),
            BadgeCatalog::A_QUARTER_100    => $this->aTroisMoisConsecutifsA100Pct($presences, $saisonCourante),
            BadgeCatalog::A_MARATHON_30    => $this->nbSeancesPresentes($presences, $saisonCourante) >= 30,
            BadgeCatalog::A_MATCH_10       => $this->nbRencontresPresentes($presences, $saisonCourante) >= 10,
            BadgeCatalog::A_VETERAN_50     => $this->nbSeancesPresentes($presences, null) >= 50,
            BadgeCatalog::A_SEASON_90      => $this->tauxPresenceSaison($presences, $saisonCourante) >= 0.90,
            default => false,
        };
    }

    // ====================================================================
    // CRITÈRES (helpers privés)
    // ====================================================================

    /** @param Presence[] $presences */
    private function aDejaUneSeancePresente(array $presences): bool
    {
        foreach ($presences as $p) {
            if ($p->isPresent() && $p->getSeance() !== null) return true;
        }
        return false;
    }

    /** @param Presence[] $presences */
    private function aDejaUneRencontrePresente(array $presences): bool
    {
        foreach ($presences as $p) {
            if ($p->isPresent() && $p->getRencontre() !== null) return true;
        }
        return false;
    }

    /**
     * Plus longue série de séances consécutives PRÉSENTES pendant la saison.
     * Une absence (avec ou sans motif) casse la série.
     *
     * @param Presence[] $presences déjà triées chrono par le repo
     */
    private function streakSeancesMax(array $presences, ?string $saison): int
    {
        $bornes = $saison !== null ? $this->bornesSaison($saison) : [null, null];
        [$debut, $fin] = $bornes;

        $max = 0;
        $courant = 0;
        foreach ($presences as $p) {
            if ($p->getSeance() === null) continue;
            $date = $p->getSeance()->getDate();
            if ($debut && $date < $debut) continue;
            if ($fin && $date > $fin) continue;

            if ($p->isPresent()) {
                $courant++;
                $max = max($max, $courant);
            } else {
                $courant = 0;
            }
        }
        return $max;
    }

    /** @param Presence[] $presences */
    private function nbSeancesPresentes(array $presences, ?string $saison): int
    {
        $bornes = $saison !== null ? $this->bornesSaison($saison) : [null, null];
        [$debut, $fin] = $bornes;

        $n = 0;
        foreach ($presences as $p) {
            if ($p->getSeance() === null) continue;
            if (!$p->isPresent()) continue;
            $date = $p->getSeance()->getDate();
            if ($debut && $date < $debut) continue;
            if ($fin && $date > $fin) continue;
            $n++;
        }
        return $n;
    }

    /** @param Presence[] $presences */
    private function nbRencontresPresentes(array $presences, ?string $saison): int
    {
        $bornes = $saison !== null ? $this->bornesSaison($saison) : [null, null];
        [$debut, $fin] = $bornes;

        $n = 0;
        foreach ($presences as $p) {
            if ($p->getRencontre() === null) continue;
            if (!$p->isPresent()) continue;
            $date = $p->getRencontre()->getDate();
            if ($debut && $date < $debut) continue;
            if ($fin && $date > $fin) continue;
            $n++;
        }
        return $n;
    }

    /**
     * Taux de présence aux séances sur la saison (entre 0 et 1).
     * @param Presence[] $presences
     */
    private function tauxPresenceSaison(array $presences, string $saison): float
    {
        [$debut, $fin] = $this->bornesSaison($saison);

        $total = 0;
        $present = 0;
        foreach ($presences as $p) {
            if ($p->getSeance() === null) continue;
            $date = $p->getSeance()->getDate();
            if ($date < $debut || $date > $fin) continue;
            $total++;
            if ($p->isPresent()) $present++;
        }
        return $total > 0 ? $present / $total : 0.0;
    }

    /**
     * Au moins 1 mois calendaire dans la saison où le joueur est présent
     * à 100% (toutes les séances de ce mois). Le mois doit comporter au
     * moins 2 séances pour valider (sinon 1 séance présente = "100%" trop facile).
     *
     * @param Presence[] $presences
     */
    private function aUnMoisCalendaireA100Pct(array $presences, string $saison): bool
    {
        return $this->aPeriodeConsecutiveA100Pct($presences, $saison, 1);
    }

    /**
     * Variante 3 mois consécutifs.
     * @param Presence[] $presences
     */
    private function aTroisMoisConsecutifsA100Pct(array $presences, string $saison): bool
    {
        return $this->aPeriodeConsecutiveA100Pct($presences, $saison, 3);
    }

    /**
     * Algorithme générique : groupe par mois (Y-m), exige $nbMoisConsecutifs
     * mois consécutifs où chaque mois a >=2 séances ET 100% présence.
     *
     * @param Presence[] $presences
     */
    private function aPeriodeConsecutiveA100Pct(
        array $presences,
        string $saison,
        int $nbMoisConsecutifs,
    ): bool {
        [$debut, $fin] = $this->bornesSaison($saison);

        // Groupage par mois Y-m
        $parMois = [];  // ['2025-10' => ['total' => N, 'present' => M], ...]
        foreach ($presences as $p) {
            if ($p->getSeance() === null) continue;
            $date = $p->getSeance()->getDate();
            if ($date < $debut || $date > $fin) continue;

            $key = $date->format('Y-m');
            $parMois[$key] ??= ['total' => 0, 'present' => 0];
            $parMois[$key]['total']++;
            if ($p->isPresent()) {
                $parMois[$key]['present']++;
            }
        }

        // Trie chronologique
        ksort($parMois);
        $clefs = array_keys($parMois);

        // Cherche une fenêtre glissante de $nbMoisConsecutifs mois 100%
        for ($i = 0; $i <= count($clefs) - $nbMoisConsecutifs; $i++) {
            $ok = true;
            for ($j = 0; $j < $nbMoisConsecutifs; $j++) {
                $mois = $parMois[$clefs[$i + $j]];
                // Mois valide : au moins 2 séances ET 100% présence
                if ($mois['total'] < 2 || $mois['present'] !== $mois['total']) {
                    $ok = false;
                    break;
                }
                // Vérifie la consécutivité des mois (pas de saut)
                if ($j > 0) {
                    $precedent = new \DateTimeImmutable($clefs[$i + $j - 1] . '-01');
                    $courant = new \DateTimeImmutable($clefs[$i + $j] . '-01');
                    $diffMois = ((int) $courant->format('Y') - (int) $precedent->format('Y')) * 12
                        + ((int) $courant->format('n') - (int) $precedent->format('n'));
                    if ($diffMois !== 1) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) return true;
        }
        return false;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function bornesSaison(string $saison): array
    {
        $anneeDebut = (int) substr($saison, 0, 4);
        return [
            new \DateTimeImmutable($anneeDebut . '-09-01 00:00:00'),
            new \DateTimeImmutable(($anneeDebut + 1) . '-06-30 23:59:59'),
        ];
    }

    private function saisonCourante(): string
    {
        $now = new \DateTimeImmutable();
        $mois = (int) $now->format('n');
        $anneeDebut = $mois >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        return $anneeDebut . '-' . ($anneeDebut + 1);
    }
}
