<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * SaisonService — source de vérité UNIQUE pour la saison sportive.
 *
 * [V2.4 05/07/2026] REFONTE : plus AUCUNE saison en dur.
 *
 * AVANT : liste const ['2026-2027','2025-2026','2024-2025'] + défaut
 * hardcodé '2026-2027' → à éditer à la main chaque année, et le défaut
 * pouvait être INCOHÉRENT avec la date réelle (défaut 2026-2027 alors
 * que la saison en cours calculée était 2025-2026).
 *
 * APRÈS :
 *   - La saison courante est CALCULÉE depuis la date : bascule au 1er
 *     septembre (convention FFBB). Le "passage à la saison d'après" est
 *     donc AUTOMATIQUE sur Manager ET PIRB (service partagé par tout le
 *     monolithe) — aucune intervention, aucun déploiement.
 *   - Les saisons disponibles sont GÉNÉRÉES : de PREMIERE_SAISON (plus
 *     ancienne donnée en base — camps 2023-2024) jusqu'à la saison
 *     courante + 1 (pour préparer la saison suivante en avance :
 *     équipes, plannings, tarifs).
 *   - Le choix manuel d'une autre saison reste possible (session
 *     'active_saison', posée par POST /saison/changer) — il est validé
 *     contre la liste générée.
 */
class SaisonService
{
    /** Plus ancienne saison avec des données en base (bilans camps 2023-2024). */
    private const PREMIERE_SAISON = '2023-2024';

    /**
     * Mois de bascule vers la saison suivante.
     * 7 = 1er JUILLET : début ADMINISTRATIF de la saison FFBB (licences,
     * mutations, préparation équipes). C'est ce que faisait déjà le club
     * en pratique : le défaut hardcodé avait été passé à 2026-2027 dès
     * juillet 2026. La bascule sportive (matchs) arrive en septembre,
     * mais l'outil de GESTION doit basculer dès juillet.
     */
    private const MOIS_BASCULE = 7;

    public function __construct(private RequestStack $requestStack) {}

    /**
     * Saisons proposées dans le sélecteur : de la saison COURANTE
     * (calculée par la date, bascule 1er juillet) à PREMIERE_SAISON.
     *
     * [06/07/2026] AUCUNE saison future : on ne peut pas sélectionner
     * 2027-2028 tant qu'elle n'a pas commencé (demande explicite de
     * Clavel — tout est piloté par le calendrier réel). La saison
     * suivante apparaîtra automatiquement le 1er juillet.
     *
     * @return string[] ex: ['2026-2027', '2025-2026', '2024-2025', '2023-2024']
     */
    public function getSaisonsDisponibles(): array
    {
        $premiereAnnee = (int) explode('-', self::PREMIERE_SAISON)[0];
        $derniereAnnee = (int) explode('-', $this->getSaisonCourante())[0];

        $saisons = [];
        for ($a = $derniereAnnee; $a >= $premiereAnnee; $a--) {
            $saisons[] = $a . '-' . ($a + 1);
        }
        return $saisons;
    }

    /**
     * Saison sportive EN COURS selon la date réelle.
     * Convention FFBB : la saison N-(N+1) démarre le 1er septembre N.
     * Ex : 05/07/2026 → '2025-2026' ; 01/09/2026 → '2026-2027'.
     */
    public function getSaisonCourante(): string
    {
        $mois  = (int) date('n');
        $annee = (int) date('Y');
        if ($mois >= self::MOIS_BASCULE) {
            return $annee . '-' . ($annee + 1);
        }
        return ($annee - 1) . '-' . $annee;
    }

    /** Saison suivante d'une saison donnée. Ex: '2025-2026' → '2026-2027'. */
    public function getSaisonSuivante(string $saison): string
    {
        [$debut, $fin] = explode('-', $saison);
        return ((int) $debut + 1) . '-' . ((int) $fin + 1);
    }

    public function getSaisonPrecedente(string $saison): string
    {
        [$debut, $fin] = explode('-', $saison);
        return ($debut - 1) . '-' . ($fin - 1);
    }

    /**
     * Saison ACTIVE pour l'utilisateur courant :
     *   1. Choix manuel en session s'il existe ET est encore valide
     *   2. Sinon la saison courante CALCULÉE (bascule auto au 1er sept.)
     *
     * NB : si un choix manuel traîne en session d'une saison devenue
     * invalide (impossible en pratique, la liste ne rétrécit pas), on
     * retombe proprement sur la saison calculée.
     */
    public function getSaisonActive(): string
    {
        $session = $this->requestStack->getSession();
        $choix   = (string) $session->get('active_saison', '');

        if ($choix !== '' && $this->isValide($choix)) {
            return $choix;
        }
        return $this->getSaisonCourante();
    }

    public function isValide(string $saison): bool
    {
        return in_array($saison, $this->getSaisonsDisponibles(), true);
    }
}
