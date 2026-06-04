<?php

namespace App\Service;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\PlanningSeance;
use App\Entity\Sport\Seance;
use App\Repository\Sport\PlanningSeanceRepository;
use App\Repository\Sport\SeanceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GenerateurSeancesService — service métier.
 *
 * Responsabilité unique : à partir des PlanningSeance d'une équipe, créer toutes
 * les Seance correspondantes sur une période donnée. Évite les doublons
 * (séances déjà créées à la même date+équipe).
 *
 * Ce service est un parfait exemple à défendre au jury pour le Bloc 2 (couches) :
 *   - Pas dans le Controller : on peut tester sans simuler HTTP, et appeler
 *     depuis n'importe quel point (UI, console command, API).
 *   - Pas dans le Repository : la logique métier (calcul de dates, gestion
 *     des doublons) ne concerne pas l'accès aux données.
 *   - Pas dans l'Entity : pas de logique de coordination (Seance ne sait pas
 *     que des PlanningSeance existent).
 *
 * C'est la couche "Service" qui orchestre.
 */
class GenerateurSeancesService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningSeanceRepository $planningRepository,
        private readonly SeanceRepository $seanceRepository,
    ) {}

    /**
     * Génère toutes les séances d'une équipe entre deux dates.
     *
     * @return array{cree:int, ignore:int, plannings:int} Statistiques pour flash message
     */
    public function genererPourEquipe(
        Equipe $equipe,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
    ): array {
        // ====================================================================
        // Récupère tous les créneaux récurrents actifs de l'équipe
        // ====================================================================
        $plannings = $this->planningRepository->findActifsByEquipe($equipe->getId());

        if (empty($plannings)) {
            return ['cree' => 0, 'ignore' => 0, 'plannings' => 0];
        }

        // ====================================================================
        // Récupère les séances déjà existantes sur la période pour éviter
        // les doublons (clé : "date_iso|equipe_id")
        // ====================================================================
        $seancesExistantes = $this->seanceRepository->createQueryBuilder('s')
            ->where('s.equipe = :equipe')
            ->andWhere('s.date BETWEEN :du AND :au')
            ->setParameter('equipe', $equipe)
            ->setParameter('du', $du)
            ->setParameter('au', $au)
            ->getQuery()->getResult();

        $existantes = [];
        foreach ($seancesExistantes as $s) {
            $existantes[$s->getDate()->format('Y-m-d H:i')] = true;
        }

        // ====================================================================
        // Pour chaque planning, calculer toutes les dates correspondantes
        // dans la période et créer les Seance manquantes
        // ====================================================================
        $countCree = 0;
        $countIgnore = 0;

        foreach ($plannings as $planning) {
            $dates = $this->calculerDatesPourPlanning($planning, $du, $au);

            foreach ($dates as $dateTime) {
                $key = $dateTime->format('Y-m-d H:i');
                if (isset($existantes[$key])) {
                    $countIgnore++;
                    continue;
                }

                $seance = new Seance();
                $seance->setClub($equipe->getClub());
                $seance->setEquipe($equipe);
                $seance->setDate($dateTime);
                $seance->setLieu($planning->getLieu());
                $seance->setDureeMinutes($planning->getDureeMinutes());
                $seance->setType($planning->getType());
                $seance->setNotes($planning->getNotes());
                $seance->setPlanningSource($planning);
                $this->em->persist($seance);

                $existantes[$key] = true;
                $countCree++;
            }
        }

        $this->em->flush();

        return [
            'cree'      => $countCree,
            'ignore'    => $countIgnore,
            'plannings' => count($plannings),
        ];
    }

    /**
     * Calcule les dates concrètes pour un PlanningSeance entre du et au.
     *
     * Exemple : planning "Mardi 18:00 du 01/09/2025 au 30/06/2026"
     *   → retourne [2025-09-02 18:00, 2025-09-09 18:00, 2025-09-16 18:00, ...]
     *
     * Pour V1 : pas de gestion des vacances scolaires. L'admin supprimera
     * manuellement les séances tombant pendant les vacances. C'est documenté
     * dans le PDF cahier fonctionnel.
     */
    private function calculerDatesPourPlanning(
        PlanningSeance $planning,
        \DateTimeImmutable $du,
        \DateTimeImmutable $au,
    ): array {
        $dates = [];

        // On part de la première occurrence du jour de semaine demandé À ou APRÈS $du
        $current = $du->setTime(0, 0);
        $jourCible = $planning->getJourSemaine(); // 1-7 (lundi=1)

        // Avancer jusqu'à tomber sur le bon jour de la semaine
        // PHP : N = jour de semaine ISO (1=lundi, 7=dimanche)
        while ((int) $current->format('N') !== $jourCible) {
            $current = $current->modify('+1 day');
            if ($current > $au) {
                return $dates; // période ne contient pas un seul jour cible
            }
        }

        // Ajoute l'heure du créneau
        [$h, $m] = explode(':', $planning->getHeureDebut());
        $current = $current->setTime((int) $h, (int) $m);

        // Boucle : ajouter une date toutes les semaines tant qu'on reste dans la période
        while ($current <= $au) {
            $dates[] = $current;
            $current = $current->modify('+7 days');
        }

        return $dates;
    }

    /**
     * Helper : retourne les bornes par défaut d'une saison sportive.
     *
     * Convention saison sportive française : 1er septembre → 30 juin.
     * Pas de séances en juillet/août (vacances d'été), même si la saison FFBB
     * officielle court d'août à juillet (on reste pragmatique côté usage club).
     *
     * Règles de détermination de la saison courante :
     *   - Si on est entre janvier et juin → saison qui a commencé l'an dernier
     *   - Si on est entre septembre et décembre → saison qui démarre cette année
     *   - Si on est en juillet/août → on prépare la saison de la rentrée
     *
     * @return array{0:\DateTimeImmutable, 1:\DateTimeImmutable}
     */
    public static function bornesSaisonCourante(): array
    {
        $now = new \DateTimeImmutable();
        $annee = (int) $now->format('Y');
        $mois = (int) $now->format('n');

        if ($mois >= 1 && $mois <= 6) {
            // Janvier-juin : on est dans la 2e moitié de la saison qui a démarré l'an dernier
            return [
                new \DateTimeImmutable(($annee - 1) . '-09-01 00:00:00'),
                new \DateTimeImmutable($annee . '-06-30 23:59:59'),
            ];
        }
        // Sept-déc OU juillet-août : on génère pour la saison qui (re)démarre en septembre
        return [
            new \DateTimeImmutable($annee . '-09-01 00:00:00'),
            new \DateTimeImmutable(($annee + 1) . '-06-30 23:59:59'),
        ];
    }
}
