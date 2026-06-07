<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Form\Manager\JoueurType;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JoueurController — gestion des joueuses du club.
 *
 * Routé sur manager.mabb.fr (cf. config/routes/manager.yaml).
 *
 * Sécurité multi-tenant :
 *   - Lecture : CLUB_MEMBER (tout membre voit l'effectif)
 *   - Écriture : CLUB_STAFF (coach, staff, dirigeant)
 *   - Archivage : CLUB_ADMIN (dirigeant uniquement)
 *
 * Le ClubVoter extrait automatiquement le club depuis le Joueur via
 * ClubAwareInterface — impossible de modifier un joueur d'un autre club.
 *
 * Spécificité Joueur : le champ "equipe" du formulaire doit être filtré
 * côté serveur pour ne proposer que les équipes du club actif. Sinon un
 * attaquant pourrait forger un POST avec equipe_id d'un autre club et
 * affecter sa joueuse à une équipe ennemie (IDOR).
 */
class JoueurController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly JoueurRepository $joueurRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des joueuses du club.
     *
     * Filtre optionnel par équipe via ?equipe_id=N dans l'URL.
     *
     *   GET manager.mabb.fr/joueuses
     *   GET manager.mabb.fr/joueuses?equipe_id=3
     */
    #[Route('/joueuses', name: 'manager_joueur_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // ====================================================================
        // Filtres depuis le query string
        // ====================================================================
        // ?equipe_id=N → filtre par équipe spécifique
        // Cast manuel : query->getInt() plante sur "" en Symfony 7+, alors qu'un
        // filtre vide est un cas légitime ("toutes les équipes").
        $equipeFiltre = null;
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipeFiltre = $this->equipeRepository->find($equipeId);
            if (!$equipeFiltre || $equipeFiltre->getClub()->getId() !== $club->getId()) {
                $this->addFlash('error', 'Équipe invalide.');
                return $this->redirectToRoute('manager_joueur_index');
            }
        }
        // ?archived=1 pour inclure les joueuses archivées
        // Cast bool tolérant pour éviter les BadRequest sur ?archived=
        $showArchived = (bool) ($request->query->get('archived') ?? false);
        // ?q=lea pour rechercher par nom/prénom (LIKE)
        $searchQuery = trim($request->query->get('q', ''));

        // ====================================================================
        // Requête Doctrine avec filtres dynamiques
        // QueryBuilder permet d'éviter du SQL en dur et d'ajouter
        // automatiquement les paramètres bindés (protection injection SQL).
        // ====================================================================
        $qb = $this->joueurRepository->createQueryBuilder('j')
            ->where('j.club = :club')
            ->setParameter('club', $club);

        if ($equipeFiltre) {
            $qb->andWhere('j.equipe = :equipe')
               ->setParameter('equipe', $equipeFiltre);
        }
        if (!$showArchived) {
            $qb->andWhere('j.isActive = true');
        }
        if ($searchQuery !== '') {
            // Recherche LIKE sur prénom OU nom OU licence
            $qb->andWhere('j.prenom LIKE :q OR j.nom LIKE :q OR j.licence LIKE :q')
               ->setParameter('q', '%' . $searchQuery . '%');
        }
        $qb->orderBy('j.nom', 'ASC')
           ->addOrderBy('j.prenom', 'ASC');

        $joueurs = $qb->getQuery()->getResult();

        // Toutes les équipes actives pour le selecteur de filtre
        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        return $this->render('manager/joueur/index.html.twig', [
            'joueurs'        => $joueurs,
            'equipes'        => $equipes,
            'equipe_filtre'  => $equipeFiltre,
            'club'           => $club,
            'show_archived'  => $showArchived,
            'search_query'   => $searchQuery,
        ]);
    }

    /**
     * Vue détail d'une joueuse — fiche complète.
     *
     *   GET manager.mabb.fr/joueuses/{id}
     *
     * Requirement {id} = \d+ pour ne pas capturer /joueuses/nouvelle.
     */
    #[Route('/joueuses/{id}', name: 'manager_joueur_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Joueur $joueur,
        \App\Repository\Sport\PresenceRepository $presenceRepository,
        \App\Gamification\XpCalculator $xpCalculator,
        \App\Repository\Sport\JoueurBadgeRepository $badgeRepository,
        \App\Service\EvaluationCalculator $evaluationCalculator,
        \App\Repository\Sport\EvaluationMatchRepository $evaluationMatchRepository,
        \App\Repository\Sport\CotisationJoueurRepository $cotisationRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $joueur);

        // ====================================================================
        // Bureau D.3.2 — Cotisation saison courante (visible joueur+staff+coach)
        // ====================================================================
        // Récupère la cotisation de la saison en cours pour affichage en lecture
        // seule sur la fiche. La gestion (paiement, exemption) reste dans
        // /tresorerie/cotisations/{id} pour le trésorier.
        $cotisationCourante = $cotisationRepository->findCouranteByJoueur($joueur);
        // is_self : true si le user connecté est ce joueur (auto-lien email match)
        $userConnecte = $this->getUser();
        $isSelf = $userConnecte instanceof \App\Entity\Core\User
            && $joueur->getUser() !== null
            && $joueur->getUser()->getId() === $userConnecte->getId();
        $isTresorier = $this->isGranted(\App\Security\Voter\TresorerieVoter::CAN_VIEW, $joueur->getClub());

        $age = null;
        if ($joueur->getDateNaissance()) {
            $age = $joueur->getDateNaissance()->diff(new \DateTimeImmutable())->y;
        }

        // ====================================================================
        // Stats de présence sur les séances (Presence avec seance NOT NULL)
        // ====================================================================
        $nbSeancesPresent = (int) $presenceRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.joueur = :joueur')
            ->andWhere('p.seance IS NOT NULL')
            ->andWhere('p.present = true')
            ->setParameter('joueur', $joueur)
            ->getQuery()->getSingleScalarResult();

        $nbSeancesTotal = (int) $presenceRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.joueur = :joueur')
            ->andWhere('p.seance IS NOT NULL')
            ->setParameter('joueur', $joueur)
            ->getQuery()->getSingleScalarResult();

        // ====================================================================
        // Stats de présence sur les rencontres
        // ====================================================================
        $nbMatchsPresent = (int) $presenceRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.joueur = :joueur')
            ->andWhere('p.rencontre IS NOT NULL')
            ->andWhere('p.present = true')
            ->setParameter('joueur', $joueur)
            ->getQuery()->getSingleScalarResult();

        $tauxPresence = $nbSeancesTotal > 0
            ? round($nbSeancesPresent / $nbSeancesTotal * 100)
            : null;

        // ====================================================================
        // Gamification : XP, niveau, badges débloqués
        // ====================================================================
        $now = new \DateTimeImmutable();
        $moisNum = (int) $now->format('n');
        $anneeDebut = $moisNum >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $saison = $anneeDebut . '-' . ($anneeDebut + 1);

        $xpSaison = $xpCalculator->xpSaison($joueur, $saison);
        $niveau   = \App\Gamification\NiveauCatalog::depuisXp($xpSaison);
        $details  = $xpCalculator->detailsSaison($joueur, $saison);
        $badges   = $badgeRepository->badgesPourJoueur($joueur, $saison);

        // ====================================================================
        // Performances Éval saison (FIBA) — moyennes + 5 derniers matchs
        // ====================================================================
        // 1 service injecté pour calcul des moyennes (agrégation centralisée),
        // 1 repository pour la liste des derniers matchs (data access pur).
        // Séparation Service/Repository assumée : moy = logique métier, list = data.
        $performancesSaison    = $evaluationCalculator->moyennesSaison($joueur, $saison);
        $evaluationsRecentes   = $evaluationMatchRepository->evaluationsRecentes($joueur, 5);

        return $this->render('manager/joueur/show.html.twig', [
            'joueur'              => $joueur,
            'age'                 => $age,
            'nb_seances_present'  => $nbSeancesPresent,
            'nb_seances_total'    => $nbSeancesTotal,
            'nb_matchs_present'   => $nbMatchsPresent,
            'taux_presence'       => $tauxPresence,
            'gamification'        => [
                'xp_saison'    => $xpSaison,
                'niveau'       => $niveau,
                'details'      => $details,
                'badges'       => $badges,
                'saison'       => $saison,
                'catalog'      => \App\Gamification\BadgeCatalog::all(),
            ],
            'performances_saison' => $performancesSaison,
            'evaluations_recentes' => $evaluationsRecentes,
            // D.3.2 — Section "Ma cotisation" sur la fiche
            'cotisation_courante' => $cotisationCourante,
            'is_self'             => $isSelf,
            'is_tresorier'        => $isTresorier,
        ]);
    }

    /**
     * Création d'une nouvelle joueuse.
     *
     *   GET  manager.mabb.fr/joueuses/nouvelle
     *   POST manager.mabb.fr/joueuses/nouvelle
     */
    #[Route('/joueuses/nouvelle', name: 'manager_joueur_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $joueur = new Joueur();
        // Force le club AVANT le formulaire — l'utilisateur ne peut pas le changer
        $joueur->setClub($club);

        // Pré-sélection d'équipe si présent dans l'URL (ex: lien "Ajouter une joueuse à U13F")
        // Cast tolérant pour éviter BadRequest sur ?equipe_id= vide
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            // Vérification de sécurité : l'équipe doit être du club actif
            if ($equipe && $equipe->getClub()->getId() === $club->getId()) {
                $joueur->setEquipe($equipe);
            }
        }

        $form = $this->createForm(JoueurType::class, $joueur, [
            // On passe le club au formulaire pour qu'il filtre la liste des équipes
            'club' => $club,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($joueur);
            $this->em->flush();

            $this->addFlash('success', sprintf('Joueuse "%s" créée.', $joueur->getNomComplet()));
            return $this->redirectToRoute('manager_joueur_index');
        }

        return $this->render('manager/joueur/new.html.twig', [
            'form'   => $form,
            'joueur' => $joueur,
            'club'   => $club,
        ]);
    }

    /**
     * Modification d'une joueuse existante.
     */
    #[Route('/joueuses/{id}/modifier', name: 'manager_joueur_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Joueur $joueur): Response
    {
        // Multi-tenant : le Voter vérifie que l'user est staff DANS LE CLUB du joueur
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        $form = $this->createForm(JoueurType::class, $joueur, [
            'club' => $joueur->getClub(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', sprintf('Joueuse "%s" mise à jour.', $joueur->getNomComplet()));
            return $this->redirectToRoute('manager_joueur_index');
        }

        return $this->render('manager/joueur/edit.html.twig', [
            'form'   => $form,
            'joueur' => $joueur,
        ]);
    }

    /**
     * Archivage d'une joueuse (soft delete).
     */
    #[Route('/joueuses/{id}/archiver', name: 'manager_joueur_archive', methods: ['POST'])]
    public function archive(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $joueur);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('archive_joueur_' . $joueur->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_index');
        }

        $joueur->setIsActive(false);
        $this->em->flush();

        $this->addFlash('success', sprintf('Joueuse "%s" archivée.', $joueur->getNomComplet()));
        return $this->redirectToRoute('manager_joueur_index');
    }

    /**
     * Réactivation d'une joueuse archivée.
     */
    #[Route('/joueuses/{id}/reactiver', name: 'manager_joueur_reactivate', methods: ['POST'])]
    public function reactivate(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $joueur);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('reactivate_joueur_' . $joueur->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_index');
        }

        $joueur->setIsActive(true);
        $this->em->flush();

        $this->addFlash('success', sprintf('Joueuse "%s" réactivée.', $joueur->getNomComplet()));
        return $this->redirectToRoute('manager_joueur_index');
    }
}
