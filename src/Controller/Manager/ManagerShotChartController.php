<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\SeanceTir;
use App\Repository\Sport\SeanceTirRepository;
use App\Security\Tenant\TenantResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shot chart V2.3 — espace Coach/Staff (Manager).
 *
 * Ce controller gère la validation des séances de tir déclarées par les joueuses.
 *
 * Routes :
 *   GET  /shot-chart/validation              → liste des séances en attente de validation
 *   POST /shot-chart/{id}/valider            → valider une séance
 *   POST /shot-chart/{id}/rejeter            → rejeter (= supprimer) une séance avec motif
 *
 * Sécurité :
 *   - ROLE_CLUB_COACH ou CLUB_STAFF minimum.
 *   - Multi-tenant : on filtre par club_id.
 *   - CSRF sur tous les POST.
 *
 * Design :
 *   Le coach ne modifie PAS les données saisies (zones).
 *   Il valide ou rejette uniquement.
 *   En cas de rejet, un message court peut être envoyé à la joueuse
 *   (affiché dans son espace PIRB, TODO notification B15).
 */
class ManagerShotChartController extends AbstractController
{
    public function __construct(
        private readonly SeanceTirRepository $seanceTirRepo,
        private readonly EntityManagerInterface $em,
        private readonly TenantResolver $tenantResolver,
    ) {}

    // =========================================================================
    // ① Liste des séances en attente
    // =========================================================================

    /**
     * GET /shot-chart/validation
     *
     * Affiche toutes les séances ENTRAINEMENT non-validées du club.
     * Groupées par joueuse pour faciliter la revue.
     */
    #[Route('/shot-chart/validation', name: 'manager_shot_chart_validation', methods: ['GET'])]
    public function validation(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();

        if ($club === null) {
            throw $this->createAccessDeniedException('Aucun club associé.');
        }

        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        $seancesEnAttente = $this->seanceTirRepo->findPendingValidation($club);

        // Grouper par joueuse
        $parJoueuse = [];
        foreach ($seancesEnAttente as $seance) {
            $joueurId = $seance->getJoueur()?->getId();
            if ($joueurId === null) continue;
            if (!isset($parJoueuse[$joueurId])) {
                $parJoueuse[$joueurId] = [
                    'joueur'   => $seance->getJoueur(),
                    'seances'  => [],
                ];
            }
            $parJoueuse[$joueurId]['seances'][] = $seance;
        }

        return $this->render('manager/shot_chart/validation.html.twig', [
            'par_joueuse' => array_values($parJoueuse),
            'total'       => count($seancesEnAttente),
        ]);
    }

    // =========================================================================
    // ② Valider une séance
    // =========================================================================

    /**
     * POST /shot-chart/{id}/valider
     *
     * Body form :
     *   _token : CSRF token 'shot_chart_valider_{id}'
     *
     * Redirige vers la page de validation avec flash de confirmation.
     */
    #[Route('/shot-chart/{id}/valider', name: 'manager_shot_chart_valider', methods: ['POST'])]
    public function valider(SeanceTir $seance, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('shot_chart_valider_' . $seance->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('manager_shot_chart_validation');
        }

        /** @var User $coach */
        $coach      = $this->getUser();
        $clubCoach  = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted('CLUB_STAFF', $seance->getClub());

        // Vérif multi-tenant
        if ($seance->getClub()?->getId() !== $clubCoach?->getId()) {
            throw $this->createAccessDeniedException('Cette séance n\'appartient pas à ton club.');
        }

        if ($seance->isValidatedByCoach()) {
            $this->addFlash('warning', 'Cette séance est déjà validée.');
            return $this->redirectToRoute('manager_shot_chart_validation');
        }

        $seance->valider($coach);
        $this->em->flush();

        $joueurNom = $seance->getJoueur()?->getNomComplet() ?? 'inconnue';
        $this->addFlash('success', sprintf(
            '✅ Séance du %s de %s validée (%d zones).',
            $seance->getDateSeance()?->format('d/m/Y') ?? '?',
            $joueurNom,
            $seance->getZones()->count()
        ));

        return $this->redirectToRoute('manager_shot_chart_validation');
    }

    // =========================================================================
    // ③ Rejeter (= supprimer) une séance
    // =========================================================================

    /**
     * POST /shot-chart/{id}/rejeter
     *
     * Body form :
     *   _token  : CSRF token 'shot_chart_rejeter_{id}'
     *   message : optionnel — texte court expliquant le rejet (affiché à la joueuse)
     *
     * Pour l'instant : supprime directement la séance (pas d'entité "rejet").
     * TODO B15 : envoyer une notification in-app à la joueuse avec le message.
     */
    #[Route('/shot-chart/{id}/rejeter', name: 'manager_shot_chart_rejeter', methods: ['POST'])]
    public function rejeter(SeanceTir $seance, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('shot_chart_rejeter_' . $seance->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('manager_shot_chart_validation');
        }

        /** @var User $coach */
        $coach      = $this->getUser();
        $clubCoach  = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted('CLUB_STAFF', $seance->getClub());

        // Vérif multi-tenant
        if ($seance->getClub()?->getId() !== $clubCoach?->getId()) {
            throw $this->createAccessDeniedException('Cette séance n\'appartient pas à ton club.');
        }

        if ($seance->isValidatedByCoach()) {
            $this->addFlash('warning', 'Impossible de rejeter une séance déjà validée.');
            return $this->redirectToRoute('manager_shot_chart_validation');
        }

        $joueurNom = $seance->getJoueur()?->getNomComplet() ?? 'inconnue';
        $date      = $seance->getDateSeance()?->format('d/m/Y') ?? '?';
        // $message = $request->request->get('message', ''); // TODO B15 notification

        $this->em->remove($seance);
        $this->em->flush();

        $this->addFlash('warning', sprintf(
            '🗑️ Séance du %s de %s supprimée (rejetée).',
            $date,
            $joueurNom
        ));

        return $this->redirectToRoute('manager_shot_chart_validation');
    }

    // =========================================================================
    // ④ Vue shot map d'une joueuse depuis Manager (lecture seule)
    // =========================================================================

    /**
     * GET /shot-chart/joueuse/{id}
     *
     * Le coach peut voir la shot map complète d'une joueuse depuis le Manager.
     * Toutes les séances validées sont affichées (pas de filtres disponibles ici).
     */
    #[Route('/shot-chart/joueuse/{id}', name: 'manager_shot_chart_joueuse', methods: ['GET'])]
    public function joueuse(\App\Entity\Sport\Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted('CLUB_STAFF', $joueur->getClub());

        // Multi-tenant
        $clubCoach = $this->tenantResolver->getCurrentClub();
        if ($joueur->getClub()?->getId() !== $clubCoach?->getId()) {
            throw $this->createAccessDeniedException();
        }

        $seancesValidees = $this->seanceTirRepo->findByJoueur($joueur, validatedOnly: true);

        // Toutes les zones pour le terrain JS
        $zonesJson = [];
        foreach ($seancesValidees as $seance) {
            foreach ($seance->getZones() as $zone) {
                $zonesJson[] = [
                    'seanceId'   => $seance->getId(),
                    'date'       => $seance->getDateSeance()?->format('Y-m-d') ?? '',
                    'source'     => $seance->getSource(),
                    'x'          => $zone->getPositionX(),
                    'y'          => $zone->getPositionY(),
                    'typeTir'    => $zone->getTypeTir(),
                    'reussis'    => $zone->getReussis(),
                    'tentatives' => $zone->getTentatives(),
                    'pct'        => $zone->getPourcentage(),
                    'couleur'    => $zone->getCouleurHsl(),
                    'label'      => $zone->getRatio(),
                ];
            }
        }

        return $this->render('manager/shot_chart/joueuse.html.twig', [
            'joueur'          => $joueur,
            'seances_validees'=> $seancesValidees,
            'zones_json'      => json_encode($zonesJson),
        ]);
    }
}
