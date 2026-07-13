<?php

namespace App\Controller\Manager;

use App\Entity\Sport\PlanningSeance;
use App\Form\Manager\PlanningSeanceGlobalType;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\PlanningSeanceRepository;
use App\Repository\Sport\RencontreRepository;
use App\Repository\Sport\SeanceRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PlanningController — vue globale du planning du club.
 *
 * Distinct des controllers Seance/Rencontre (qui gèrent les événements
 * individuels) et de PlanningSeanceController (qui gère les CRUD d'un
 * créneau récurrent à partir de la fiche équipe).
 *
 * Cette vue agrège :
 *   - TOUS les créneaux récurrents du club (toutes équipes)
 *   - Optionnellement, séances ponctuelles et rencontres (à venir étape 2)
 *
 * Sert à donner à Willy/l'admin une vue d'ensemble pour repérer les conflits
 * de salle, les chevauchements d'horaires, les créneaux libres, etc.
 */
class PlanningController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly PlanningSeanceRepository $planningRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly SeanceRepository $seanceRepository,
        private readonly RencontreRepository $rencontreRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Vue planning hebdo type — grille jours × heures.
     *
     *   GET /manager/planning
     *   GET /manager/planning?categorie=U13   → filtre par catégorie
     *   GET /manager/planning?equipe_id=3     → filtre par équipe
     */
    #[Route('/planning', name: 'manager_planning_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // ====================================================================
        // Filtres : ?categorie=U13, ?equipe_id=N
        // ====================================================================
        $categorieFiltre = $request->query->get('categorie', '');
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        $equipeFiltre = $equipeId > 0 ? $this->equipeRepository->find($equipeId) : null;
        if ($equipeFiltre && $equipeFiltre->getClub()->getId() !== $club->getId()) {
            $equipeFiltre = null;
        }

        // ====================================================================
        // Récupération des plannings du club (toutes équipes actives)
        // ====================================================================
        $qb = $this->planningRepository->createQueryBuilder('p')
            ->join('p.equipe', 'e')
            ->where('p.club = :club')
            ->andWhere('p.isActive = true')
            ->andWhere('e.isActive = true')
            ->setParameter('club', $club);

        if ($equipeFiltre) {
            $qb->andWhere('p.equipe = :equipe')
               ->setParameter('equipe', $equipeFiltre);
        } elseif ($categorieFiltre !== '') {
            $qb->andWhere('e.categorie = :categorie')
               ->setParameter('categorie', $categorieFiltre);
        }

        $qb->orderBy('p.jourSemaine', 'ASC')
           ->addOrderBy('p.heureDebut', 'ASC');

        $plannings = $qb->getQuery()->getResult();

        // ====================================================================
        // Organisation pour le template : groupage par jour de la semaine
        //   $planningsParJour = [1 => [planning1, planning2], 2 => [...], ...]
        // ====================================================================
        $planningsParJour = array_fill_keys(range(1, 7), []);
        foreach ($plannings as $p) {
            $planningsParJour[$p->getJourSemaine()][] = $p;
        }

        // ====================================================================
        // Listes pour les filtres
        // ====================================================================
        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        // Catégories distinctes qui ont des équipes actives
        $categories = $this->equipeRepository->createQueryBuilder('e')
            ->select('DISTINCT e.categorie')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->setParameter('club', $club)
            ->orderBy('e.categorie', 'ASC')
            ->getQuery()->getSingleColumnResult();

        return $this->render('manager/planning/index.html.twig', [
            'plannings_par_jour' => $planningsParJour,
            'equipes'            => $equipes,
            'categories'         => $categories,
            'categorie_filtre'   => $categorieFiltre,
            'equipe_filtre'      => $equipeFiltre,
            'club'               => $club,
        ]);
    }

    /**
     * La semaine du coach : ses entraînements réels, jour par jour, avec l'appel.
     *
     *   GET /manager/planning/semaine
     *   GET /manager/planning/semaine?debut=2026-07-20     → une autre semaine
     *   GET /manager/planning/semaine?categorie=U13
     *   GET /manager/planning/semaine?tout=1               → tout le club
     *
     * Différence avec /planning, qui montre la semaine TYPE, abstraite : « le mardi,
     * il y a les U13 à 18h ». Ici on montre la semaine RÉELLE : « mardi 14 juillet,
     * U13, appel pas encore fait ». C'est la seule sur laquelle on peut agir.
     *
     * Par défaut, un coach ne voit que ses équipes. Il peut demander tout le club,
     * mais ce n'est pas le défaut : sur un club de vingt équipes, la liste devient
     * illisible et il ne trouve plus les siennes.
     */
    #[Route('/planning/semaine', name: 'manager_planning_semaine', methods: ['GET'])]
    public function semaine(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');

            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        // On se cale toujours sur le lundi : une semaine qui commencerait un jeudi
        // ne veut rien dire pour personne.
        $debut = $request->query->get('debut');
        try {
            $lundi = $debut
                ? new \DateTimeImmutable($debut)
                : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $lundi = new \DateTimeImmutable('today');
        }
        $lundi = $lundi->modify('monday this week')->setTime(0, 0);

        $categorie = trim((string) $request->query->get('categorie', '')) ?: null;

        // « Tout le club » : réservé à ceux qui pilotent le club, pas à un coach
        // qui cherche ses créneaux. Un coach sans équipe assignée voit tout aussi,
        // sinon il aurait un écran vide sans comprendre pourquoi.
        $user            = $this->getUser();
        $peutVoirTout    = $this->isGranted(ClubVoter::CLUB_SECRETARIAT, $club);
        $aDesEquipes     = $this->equipeRepository->countPourCoach($user, $club) > 0;
        $voirTout        = $request->query->getBoolean('tout', false) && $peutVoirTout;
        $filtrerSurCoach = !$voirTout && $aDesEquipes;

        $seances = $this->seanceRepository->findSemaine(
            $club,
            $lundi,
            $filtrerSurCoach ? $user : null,
            $categorie,
        );

        // Groupage par jour ISO (lundi = 1). Les jours vides restent présents :
        // voir « mercredi, rien » est une information, pas un trou dans la page.
        $parJour = array_fill_keys(range(1, 7), []);
        foreach ($seances as $s) {
            $parJour[(int) $s->getDate()->format('N')][] = $s;
        }

        $categories = $this->equipeRepository->createQueryBuilder('e')
            ->select('DISTINCT e.categorie')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->setParameter('club', $club)
            ->orderBy('e.categorie', 'ASC')
            ->getQuery()->getSingleColumnResult();

        return $this->render('manager/planning/semaine.html.twig', [
            'club'              => $club,
            'lundi'             => $lundi,
            'seances_par_jour'  => $parJour,
            'nb_seances'        => \count($seances),
            'categories'        => $categories,
            'categorie_filtre'  => $categorie,
            'voir_tout'         => $voirTout,
            'peut_voir_tout'    => $peutVoirTout,
            'filtre_sur_coach'  => $filtrerSurCoach,
            'a_des_equipes'     => $aDesEquipes,
        ]);
    }

    /**
     * Ajout d'un créneau récurrent depuis la vue Planning globale.
     *
     * Différence avec PlanningSeanceController::new : ici l'équipe N'EST PAS
     * pré-sélectionnée, donc on doit utiliser un autre form (PlanningSeanceGlobalType)
     * qui inclut le champ équipe.
     */
    #[Route('/planning/creneau/nouveau', name: 'manager_planning_global_new', methods: ['GET', 'POST'])]
    public function newCreneau(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $planning = new PlanningSeance();
        $planning->setClub($club);

        $form = $this->createForm(PlanningSeanceGlobalType::class, $planning, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($planning);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Créneau récurrent ajouté pour %s : %s.',
                $planning->getEquipe()->getNom(),
                $planning->getResume()
            ));
            return $this->redirectToRoute('manager_planning_index');
        }

        return $this->render('manager/planning/new_global.html.twig', [
            'form' => $form,
        ]);
    }
}
