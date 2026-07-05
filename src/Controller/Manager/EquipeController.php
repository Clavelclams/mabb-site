<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Form\Manager\EquipeType;
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
 * EquipeController — gestion des équipes du club.
 *
 * Routé sur manager.mabb.fr (cf. config/routes/manager.yaml).
 * Tous les endpoints sont protégés par le ClubVoter :
 *   - lecture (index, show)        → CLUB_MEMBER  (tout membre du club)
 *   - écriture (new, edit)         → CLUB_STAFF   (coach, staff, dirigeant)
 *   - destruction (archive)        → CLUB_ADMIN   (dirigeant uniquement)
 *
 * Multi-tenant : le TenantResolver fournit le club actif depuis la session.
 * Toute écriture force le club via $equipe->setClub($currentClub) — impossible
 * pour un utilisateur de créer une équipe dans un autre club que le sien.
 */
class EquipeController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EquipeRepository $equipeRepository,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\SaisonService $saisonService,
        private readonly \App\Service\Sport\CategorieCalculator $categorieCalculator,
    ) {}

    /**
     * Liste de toutes les équipes du club actif.
     *
     *   GET manager.mabb.fr/equipes
     */
    #[Route('/equipes', name: 'manager_equipe_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif. Sélectionne un club pour continuer.');
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // ====================================================================
        // Filtres depuis le query string
        // ====================================================================
        $saisonCourante = $this->getSaisonCourante();
        // ?saison=2025-2026 (saison spécifique) | ?saison=all (toutes) | défaut = saison courante
        $saisonFiltre = $request->query->get('saison', $saisonCourante);
        // ?archived=1 pour afficher les archivées
        // Cast bool tolérant — getBoolean() de Symfony 7+ peut planter sur "".
        $showArchived = (bool) ($request->query->get('archived') ?? false);

        // ====================================================================
        // Récupération des équipes selon filtres
        // ====================================================================
        $criteria = ['club' => $club];
        if ($saisonFiltre !== 'all') {
            $criteria['saison'] = $saisonFiltre;
        }
        if (!$showArchived) {
            $criteria['isActive'] = true;
        }
        $equipes = $this->equipeRepository->findBy($criteria);

        // ====================================================================
        // Tri par catégorie naturelle (U7 → U18 → Séniors → 3x3 → Loisir)
        // au lieu de l'ordre alphabétique (Séniors < U13...) qui n'a aucun sens.
        // On utilise l'index dans Equipe::CATEGORIES comme rang.
        // ====================================================================
        $rangCategorie = array_flip(Equipe::CATEGORIES);
        usort($equipes, function(Equipe $a, Equipe $b) use ($rangCategorie) {
            $rangA = $rangCategorie[$a->getCategorie()] ?? PHP_INT_MAX;
            $rangB = $rangCategorie[$b->getCategorie()] ?? PHP_INT_MAX;
            if ($rangA !== $rangB) return $rangA <=> $rangB;
            return strcmp($a->getNom(), $b->getNom());
        });

        // Liste des saisons existantes pour le selecteur
        $saisonsDisponibles = $this->equipeRepository
            ->createQueryBuilder('e')
            ->select('DISTINCT e.saison')
            ->where('e.club = :club')
            ->setParameter('club', $club)
            ->orderBy('e.saison', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        // [FIX 06/07/2026] La saison active doit TOUJOURS être proposée,
        // même si aucune équipe n'existe encore dedans (début de saison :
        // on repart de zéro et on recompose — les saisons passées restent
        // consultables comme archives via ce même sélecteur).
        if (!in_array($saisonCourante, $saisonsDisponibles, true)) {
            array_unshift($saisonsDisponibles, $saisonCourante);
        }

        return $this->render('manager/equipe/index.html.twig', [
            'equipes'             => $equipes,
            'club'                => $club,
            'saison_filtre'       => $saisonFiltre,
            'saison_courante'     => $saisonCourante,
            'saisons_disponibles' => $saisonsDisponibles,
            'show_archived'       => $showArchived,
        ]);
    }

    /**
     * Vue détail d'une équipe — fiche complète avec joueuses + prochains événements.
     *
     *   GET manager.mabb.fr/equipes/{id}
     *
     * IMPORTANT : on contraint {id} à \d+ (chiffres uniquement) pour éviter que
     * cette route capture aussi /equipes/nouvelle (qui matcherait avec id='nouvelle').
     * Les routes Symfony sont évaluées dans l'ordre, ce requirement les distingue.
     */
    #[Route('/equipes/{id}', name: 'manager_equipe_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Equipe $equipe,
        \App\Repository\Sport\PlanningSeanceRepository $planningRepo,
        \App\Repository\Sport\CotisationJoueurRepository $cotisationRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $equipe);

        $joueurs = $equipe->getJoueurs()->toArray();
        usort($joueurs, fn($a, $b) => strcmp($a->getNom(), $b->getNom()));

        $effectif       = count($joueurs);
        $effectifActif  = count(array_filter($joueurs, fn($j) => $j->isActive()));

        // Plannings récurrents (créneaux hebdo d'entraînement)
        $plannings = $planningRepo->findActifsByEquipe($equipe->getId());

        // ====================================================================
        // Bureau D.3.2 — Map cotisations saison courante par joueur
        // ====================================================================
        // Optim N+1 : 1 seule requête pour récupérer toutes les cotisations,
        // puis indexation côté PHP par joueur_id pour lookup O(1) dans le template.
        // Visible uniquement par CLUB_STAFF (coach/dirigeant/staff) — masqué
        // pour un joueur lambda qui regarderait la page équipe.
        $cotisationsMap = [];
        if ($this->isGranted(ClubVoter::CLUB_STAFF, $equipe)) {
            $saisonCourante = \App\Entity\Sport\CotisationJoueur::getSaisonCourante();
            $toutes = $cotisationRepository->createQueryBuilder('c')
                ->where('c.saison = :saison')
                ->andWhere('c.joueur IN (:joueurs)')
                ->setParameter('saison', $saisonCourante)
                ->setParameter('joueurs', $joueurs)
                ->getQuery()
                ->getResult();
            foreach ($toutes as $c) {
                /** @var \App\Entity\Sport\CotisationJoueur $c */
                $cotisationsMap[$c->getJoueur()->getId()] = $c;
            }
        }

        return $this->render('manager/equipe/show.html.twig', [
            'equipe'           => $equipe,
            'joueurs'          => $joueurs,
            'effectif'         => $effectif,
            'effectif_actif'   => $effectifActif,
            'plannings'        => $plannings,
            'cotisations_map'  => $cotisationsMap,
        ]);
    }

    /**
     * Création d'une nouvelle équipe.
     *
     *   GET  manager.mabb.fr/equipes/nouvelle  → affiche le formulaire
     *   POST manager.mabb.fr/equipes/nouvelle  → traite le formulaire
     */
    #[Route('/equipes/nouvelle', name: 'manager_equipe_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        // Sécurité : seul un staff (coach/dirigeant) peut créer une équipe
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $equipe = new Equipe();
        // Force le club AVANT le formulaire — l'utilisateur ne peut pas le changer
        $equipe->setClub($club);
        // Valeur par défaut sympa pour la saison en cours
        $equipe->setSaison($this->getSaisonCourante());

        $form = $this->createForm(EquipeType::class, $equipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($equipe);
            $this->em->flush();

            $this->addFlash('success', sprintf('Équipe "%s" créée.', $equipe->getNom()));
            return $this->redirectToRoute('manager_equipe_index');
        }

        return $this->render('manager/equipe/new.html.twig', [
            'form'   => $form,
            'equipe' => $equipe,
            'club'   => $club,
        ]);
    }

    /**
     * Modification d'une équipe existante.
     *
     *   GET  manager.mabb.fr/equipes/{id}/modifier
     *   POST manager.mabb.fr/equipes/{id}/modifier
     */
    #[Route('/equipes/{id}/modifier', name: 'manager_equipe_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Equipe $equipe): Response
    {
        // Sécurité multi-tenant : le ClubVoter vérifie que l'user est staff
        // DANS LE CLUB de l'équipe — impossible de modifier une équipe d'un autre club.
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $equipe);

        $form = $this->createForm(EquipeType::class, $equipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', sprintf('Équipe "%s" mise à jour.', $equipe->getNom()));
            return $this->redirectToRoute('manager_equipe_index');
        }

        return $this->render('manager/equipe/edit.html.twig', [
            'form'   => $form,
            'equipe' => $equipe,
        ]);
    }

    /**
     * Archivage d'une équipe (soft delete : isActive = false).
     *
     * On ne supprime jamais vraiment une équipe car l'historique des joueuses,
     * matchs et bilans y est rattaché. On la sort juste des listes courantes.
     *
     *   POST manager.mabb.fr/equipes/{id}/archiver  + token CSRF
     */
    #[Route('/equipes/{id}/archiver', name: 'manager_equipe_archive', methods: ['POST'])]
    public function archive(Request $request, Equipe $equipe): Response
    {
        // Sécurité : seul un dirigeant peut archiver
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $equipe);

        // Protection CSRF : le formulaire d'archivage doit envoyer un token valide.
        // Empêche un attaquant de forger une requête depuis un autre site.
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('archive_equipe_' . $equipe->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Réessaie.');
            return $this->redirectToRoute('manager_equipe_index');
        }

        $equipe->setIsActive(false);
        $this->em->flush();

        $this->addFlash('success', sprintf('Équipe "%s" archivée.', $equipe->getNom()));
        return $this->redirectToRoute('manager_equipe_index');
    }

    /**
     * Réactivation d'une équipe archivée.
     *
     *   POST manager.mabb.fr/equipes/{id}/reactiver  + token CSRF
     */
    #[Route('/equipes/{id}/reactiver', name: 'manager_equipe_reactivate', methods: ['POST'])]
    public function reactivate(Request $request, Equipe $equipe): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $equipe);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('reactivate_equipe_' . $equipe->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_equipe_index');
        }

        $equipe->setIsActive(true);
        $this->em->flush();

        $this->addFlash('success', sprintf('Équipe "%s" réactivée.', $equipe->getNom()));
        return $this->redirectToRoute('manager_equipe_index');
    }

    /**
     * Composer l'effectif : page d'affectation en masse de joueuses à cette équipe.
     *
     * Workflow :
     *   - GET   : affiche TOUTES les joueuses du club non affectées + celles
     *             déjà dans cette équipe (pré-cochées), avec filtres.
     *   - POST  : reçoit la liste des IDs cochés, met à jour les affectations
     *             en masse (ajoute les nouvelles, retire celles décochées).
     *
     * Choix métier V1 : on n'affiche PAS les joueuses déjà dans une autre
     * équipe. Pour les déplacer, il faut d'abord les détacher manuellement
     * de leur équipe actuelle. Évite les transferts accidentels.
     *
     *   GET/POST manager.mabb.fr/equipes/{id}/composer
     */
    #[Route('/equipes/{id}/composer', name: 'manager_equipe_composer', methods: ['GET', 'POST'])]
    public function composer(
        Request $request,
        Equipe $equipe,
        JoueurRepository $joueurRepository,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $equipe);
        $club = $equipe->getClub();

        // ====================================================================
        // POST : appliquer les changements d'affectation
        // ====================================================================
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('composer_' . $equipe->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_equipe_composer', ['id' => $equipe->getId()]);
            }

            // IDs des joueuses cochées dans le formulaire
            $idsCochees = array_map('intval', array_keys($request->request->all('joueuses')));

            $countAjout = 0;
            $countRetrait = 0;

            // 1. Retirer celles qui sont actuellement dans l'équipe mais décochées
            foreach ($equipe->getJoueurs() as $joueur) {
                if (!in_array($joueur->getId(), $idsCochees, true)) {
                    $joueur->setEquipe(null);
                    $countRetrait++;
                }
            }

            // 2. Ajouter les joueuses libres cochées (on re-fetch les libres
            //    après le détachement éventuel de l'étape 1).
            $libres = $joueurRepository->findBy([
                'club'     => $club,
                'equipe'   => null,
                'isActive' => true,
            ]);
            foreach ($libres as $joueur) {
                if (in_array($joueur->getId(), $idsCochees, true)) {
                    $joueur->setEquipe($equipe);
                    $countAjout++;
                }
            }

            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Effectif mis à jour : %d ajout(s), %d retrait(s).',
                $countAjout,
                $countRetrait
            ));
            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        // ====================================================================
        // GET : préparer la liste filtrable
        // ====================================================================
        $search     = trim((string) $request->query->get('q', ''));
        $catFiltre  = (string) $request->query->get('cat_age', '');

        // Récupère toutes les joueuses actives du club qui sont SOIT libres
        // SOIT déjà dans cette équipe (les autres = exclues, pas pertinent ici).
        $qb = $joueurRepository->createQueryBuilder('j')
            ->andWhere('j.club = :club')->setParameter('club', $club)
            ->andWhere('j.isActive = true')
            ->andWhere('j.equipe IS NULL OR j.equipe = :equipe')->setParameter('equipe', $equipe);

        if ($search !== '') {
            $qb->andWhere('j.nom LIKE :s OR j.prenom LIKE :s OR j.licence LIKE :s')
               ->setParameter('s', '%' . $search . '%');
        }

        $qb->orderBy('j.nom', 'ASC');
        $joueurs = $qb->getQuery()->getResult();

        // IDs déjà affectées à cette équipe (pour pré-cocher dans la vue)
        $idsActuels = array_map(fn($j) => $j->getId(), $equipe->getJoueurs()->toArray());

        // Calcul de la catégorie d'âge pour chaque joueuse (saison courante)
        // pour permettre le filtre côté Twig et l'affichage.
        $apercu = [];
        foreach ($joueurs as $j) {
            $catAge = $this->categorieAgeJoueur($j);
            // Filtre catégorie d'âge si demandé
            if ($catFiltre !== '' && $catAge !== $catFiltre) {
                continue;
            }
            $apercu[] = [
                'joueur'   => $j,
                'cat_age'  => $catAge,
                'is_dans_equipe' => in_array($j->getId(), $idsActuels, true),
            ];
        }

        // Liste des catégories d'âge présentes pour peupler le dropdown filtre
        $categoriesPresentes = array_values(array_unique(array_filter(
            array_map(fn($a) => $a['cat_age'], $apercu)
        )));
        sort($categoriesPresentes);

        return $this->render('manager/equipe/composer.html.twig', [
            'equipe'                => $equipe,
            'apercu'                => $apercu,
            'nb_total'              => count($apercu),
            'nb_dans_equipe'        => count($idsActuels),
            'search_query'          => $search,
            'cat_filtre'            => $catFiltre,
            'categories_presentes'  => $categoriesPresentes,
        ]);
    }

    /**
     * Calcule la catégorie d'âge FFBB d'un joueur pour la saison courante.
     * Réplique la logique de TrombinoscopeParserService::categorieSaisonCourante()
     * mais ici on l'applique sur un Joueur déjà en base (DateNaissance connue).
     *
     * Renvoie une catégorie de Equipe::CATEGORIES ou null si DateNaissance vide.
     */
    private function categorieAgeJoueur(Joueur $joueur): ?string
    {
        // [FIX 06/07/2026] L'âge est calculé pour la SAISON ACTIVE du
        // sélecteur (via CategorieCalculator, règle FFBB) et non plus pour
        // une saison locale recalculée. Effet attendu par Clavel : en
        // basculant sur 2026-2027, une U10 de l'an dernier apparaît U11 —
        // les catégories suivent l'âge automatiquement, saison par saison.
        $age = $this->categorieCalculator->ageReference(
            $joueur,
            $this->saisonService->getSaisonActive()
        );
        if ($age === null) {
            return null;
        }

        if ($age >= 19) {
            // Sans champ sexe sur Joueur, on ne peut pas distinguer F/H ici.
            // On retourne "Senior" générique pour le filtre — le coach affecte
            // ensuite à une équipe Senior F ou Senior H selon ses besoins.
            return 'Senior';
        }
        if ($age === 18) return 'U18';
        if ($age >= 16)  return 'U17';
        if ($age >= 14)  return 'U15';
        if ($age >= 12)  return 'U13';
        if ($age >= 10)  return 'U11';
        if ($age >= 8)   return 'U9';
        return 'U7';
    }

    /**
     * [FIX 06/07/2026] Délègue à SaisonService::getSaisonActive().
     *
     * AVANT : logique locale à bascule septembre → la page Équipes affichait
     * "2025-2026" alors que le sélecteur global de la navbar disait
     * "2026-2027". C'était LE bug signalé par Clavel. Désormais UNE seule
     * source de vérité : le sélecteur global (bascule auto au 1er juillet,
     * choix manuel respecté).
     */
    private function getSaisonCourante(): string
    {
        return $this->saisonService->getSaisonActive();
    }
}
