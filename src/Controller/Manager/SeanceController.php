<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Seance;
use App\Form\Manager\SeanceType;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\FeedbackSeanceRepository;
use App\Repository\Sport\SeanceRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SeanceController — gestion des entraînements (séances).
 *
 * Une séance = entraînement collectif d'une équipe à un moment donné.
 * Distincte d'une Rencontre (= match contre un adversaire).
 *
 * Multi-tenant : ClubVoter via ClubAwareInterface sur l'entité Seance.
 *
 * Pas encore implémenté (sera dans les étapes suivantes du bloc Calendrier) :
 *   - Création récurrente (« tous les mardis 18h pendant 3 mois »)
 *   - Vue calendrier mensuel (FullCalendar.js)
 *   - Pointage de présence post-séance
 *   - Export iCal
 */
class SeanceController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly SeanceRepository $seanceRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des séances avec filtres : équipe + période.
     *
     *   GET /manager/seances
     *   GET /manager/seances?equipe_id=3&periode=passees
     */
    #[Route('/seances', name: 'manager_seance_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // ====================================================================
        // Filtres : ?equipe_id=N (équipe spécifique), ?periode=a_venir|passees|toutes
        // ====================================================================
        $equipeFiltre = null;
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipeFiltre = $this->equipeRepository->find($equipeId);
            if (!$equipeFiltre || $equipeFiltre->getClub()->getId() !== $club->getId()) {
                $this->addFlash('error', 'Équipe invalide.');
                return $this->redirectToRoute('manager_seance_index');
            }
        }

        // Par défaut on affiche les séances à venir (ce qui compte au quotidien)
        $periode = $request->query->get('periode', 'a_venir');
        $now = new \DateTimeImmutable();

        // ====================================================================
        // Construction de la requête avec QueryBuilder (multi-tenant + filtres)
        // ====================================================================
        $qb = $this->seanceRepository->createQueryBuilder('s')
            ->where('s.club = :club')
            ->setParameter('club', $club);

        if ($equipeFiltre) {
            $qb->andWhere('s.equipe = :equipe')
               ->setParameter('equipe', $equipeFiltre);
        }

        switch ($periode) {
            case 'a_venir':
                $qb->andWhere('s.date >= :now')
                   ->setParameter('now', $now)
                   ->orderBy('s.date', 'ASC');
                break;
            case 'passees':
                $qb->andWhere('s.date < :now')
                   ->setParameter('now', $now)
                   ->orderBy('s.date', 'DESC');
                break;
            default: // 'toutes'
                $qb->orderBy('s.date', 'DESC');
        }

        $seances = $qb->getQuery()->getResult();

        // Equipes actives pour le filtre
        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        return $this->render('manager/seance/index.html.twig', [
            'seances'       => $seances,
            'equipes'       => $equipes,
            'equipe_filtre' => $equipeFiltre,
            'periode'       => $periode,
            'club'          => $club,
        ]);
    }

    /**
     * Vue détail d'une séance.
     *
     * Les retours des joueuses ne sont montrés qu'à l'encadrement, jamais aux
     * simples membres. Et jamais en dessous du seuil de réponses : sur une séance
     * où une seule joueuse s'est exprimée, afficher sa note reviendrait à la
     * désigner du doigt.
     */
    #[Route('/seances/{id}', name: 'manager_seance_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Seance $seance, FeedbackSeanceRepository $feedbackRepo): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $seance);

        $retours = $this->isGranted(ClubVoter::CLUB_STAFF, $seance)
            ? $feedbackRepo->synthesePourSeance($seance)
            : null;

        return $this->render('manager/seance/show.html.twig', [
            'seance'  => $seance,
            'retours' => $retours,
        ]);
    }

    /**
     * Création d'une nouvelle séance.
     */
    #[Route('/seances/nouvelle', name: 'manager_seance_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $seance = new Seance();
        $seance->setClub($club);

        // Pré-sélection d'équipe via query string (depuis fiche équipe)
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            if ($equipe && $equipe->getClub()->getId() === $club->getId()) {
                $seance->setEquipe($equipe);
            }
        }

        $form = $this->createForm(SeanceType::class, $seance, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($seance);
            $this->em->flush();
            $this->addFlash('success', 'Séance créée.');
            return $this->redirectToRoute('manager_seance_show', ['id' => $seance->getId()]);
        }

        return $this->render('manager/seance/new.html.twig', [
            'form'   => $form,
            'seance' => $seance,
            'club'   => $club,
        ]);
    }

    /**
     * Modification d'une séance existante.
     */
    #[Route('/seances/{id}/modifier', name: 'manager_seance_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Seance $seance): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $seance);

        $etaitAttacheePlanning = $seance->estIssueDunPlanning();

        $form = $this->createForm(SeanceType::class, $seance, ['club' => $seance->getClub()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ============================================================
            // DÉTACHEMENT DU PLANNING SOURCE
            // Si la séance était issue d'un planning récurrent et que
            // l'utilisateur la modifie individuellement, on la détache.
            // Ainsi, une future régénération du planning ne l'écrasera pas.
            // C'est le mécanisme d'override individuel sur pattern récurrent.
            // ============================================================
            if ($etaitAttacheePlanning) {
                $seance->setPlanningSource(null);
                $this->addFlash('info', 'Cette séance a été détachée de son créneau récurrent (modification individuelle). Elle n\'est plus impactée par les régénérations.');
            }

            $this->em->flush();
            $this->addFlash('success', 'Séance mise à jour.');
            return $this->redirectToRoute('manager_seance_show', ['id' => $seance->getId()]);
        }

        return $this->render('manager/seance/edit.html.twig', [
            'form'   => $form,
            'seance' => $seance,
        ]);
    }

    /**
     * Suppression d'une séance.
     *
     * Contrairement aux équipes/joueuses, on supprime VRAIMENT la séance
     * (pas de soft delete) parce qu'une séance est un événement ponctuel :
     * si on l'annule, on n'a aucune raison de garder une trace si elle n'a
     * pas eu lieu et qu'aucune présence n'a été pointée.
     */
    #[Route('/seances/{id}/supprimer', name: 'manager_seance_delete', methods: ['POST'])]
    public function delete(Request $request, Seance $seance): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $seance);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_seance_' . $seance->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_seance_index');
        }

        $this->em->remove($seance);
        $this->em->flush();

        $this->addFlash('success', 'Séance supprimée.');
        return $this->redirectToRoute('manager_seance_index');
    }
}
