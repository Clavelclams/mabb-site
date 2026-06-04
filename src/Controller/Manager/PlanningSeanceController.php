<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\PlanningSeance;
use App\Form\Manager\PlanningSeanceType;
use App\Security\Voter\ClubVoter;
use App\Service\GenerateurSeancesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PlanningSeanceController — gestion des créneaux récurrents d'entraînement.
 *
 * Les plannings sont créés EN CONTEXTE d'une équipe (toujours depuis sa fiche),
 * d'où le pattern d'URL /equipes/{equipe_id}/plannings/... plutôt qu'un index
 * global.
 */
class PlanningSeanceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenerateurSeancesService $generateur,
    ) {}

    /**
     * Création d'un nouveau créneau récurrent pour une équipe.
     *
     *   GET  /equipes/{id}/plannings/nouveau
     *   POST /equipes/{id}/plannings/nouveau
     */
    #[Route('/equipes/{id}/plannings/nouveau', name: 'manager_planning_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Request $request, Equipe $equipe): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $equipe);

        $planning = new PlanningSeance();
        $planning->setClub($equipe->getClub());
        $planning->setEquipe($equipe);

        $form = $this->createForm(PlanningSeanceType::class, $planning);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($planning);
            $this->em->flush();
            $this->addFlash('success', sprintf('Créneau récurrent ajouté : %s.', $planning->getResume()));
            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        return $this->render('manager/planning/new.html.twig', [
            'form'   => $form,
            'equipe' => $equipe,
        ]);
    }

    /**
     * Modification d'un créneau récurrent.
     */
    #[Route('/plannings/{id}/modifier', name: 'manager_planning_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, PlanningSeance $planning): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $planning);

        $form = $this->createForm(PlanningSeanceType::class, $planning);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Créneau récurrent mis à jour.');
            return $this->redirectToRoute('manager_equipe_show', ['id' => $planning->getEquipe()->getId()]);
        }

        return $this->render('manager/planning/edit.html.twig', [
            'form'     => $form,
            'planning' => $planning,
            'equipe'   => $planning->getEquipe(),
        ]);
    }

    /**
     * Suppression d'un créneau récurrent.
     *
     * Les séances déjà générées ne sont PAS supprimées (perte d'historique
     * inutile), elles deviennent juste "détachées" automatiquement via
     * ON DELETE SET NULL sur la FK planning_source.
     */
    #[Route('/plannings/{id}/supprimer', name: 'manager_planning_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, PlanningSeance $planning): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $planning);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_planning_' . $planning->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_equipe_show', ['id' => $planning->getEquipe()->getId()]);
        }

        $equipeId = $planning->getEquipe()->getId();
        $this->em->remove($planning);
        $this->em->flush();

        $this->addFlash('success', 'Créneau récurrent supprimé. Les séances déjà créées sont conservées (détachées).');
        return $this->redirectToRoute('manager_equipe_show', ['id' => $equipeId]);
    }

    /**
     * Génération des séances de la saison pour une équipe.
     *
     * Utilise le GenerateurSeancesService. Calcule automatiquement les bornes
     * de la saison courante (août → juillet).
     *
     *   POST /equipes/{id}/plannings/generer
     */
    #[Route('/equipes/{id}/plannings/generer', name: 'manager_planning_generer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generer(Request $request, Equipe $equipe): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $equipe);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('generer_seances_' . $equipe->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        [$du, $au] = GenerateurSeancesService::bornesSaisonCourante();
        $stats = $this->generateur->genererPourEquipe($equipe, $du, $au);

        if ($stats['plannings'] === 0) {
            $this->addFlash('warning', 'Aucun créneau récurrent défini. Ajoute d\'abord un créneau avant de générer.');
        } elseif ($stats['cree'] === 0) {
            $this->addFlash('info', sprintf('Aucune nouvelle séance à créer. %d séance(s) existaient déjà sur la période.', $stats['ignore']));
        } else {
            $this->addFlash('success', sprintf(
                '%d séance(s) générée(s) du %s au %s à partir de %d créneau(x) récurrent(s).%s',
                $stats['cree'],
                $du->format('d/m/Y'),
                $au->format('d/m/Y'),
                $stats['plannings'],
                $stats['ignore'] > 0 ? sprintf(' (%d déjà existantes ignorées)', $stats['ignore']) : ''
            ));
        }

        return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
    }
}
