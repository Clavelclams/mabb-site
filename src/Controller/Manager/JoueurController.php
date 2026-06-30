<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\JoueurEquipe;
use App\Form\Manager\JoueurType;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurEquipeRepository;
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
        Request $request,
        \App\Repository\Sport\PresenceRepository $presenceRepository,
        \App\Gamification\XpCalculator $xpCalculator,
        \App\Repository\Sport\JoueurBadgeRepository $badgeRepository,
        \App\Service\EvaluationCalculator $evaluationCalculator,
        \App\Repository\Sport\EvaluationMatchRepository $evaluationMatchRepository,
        \App\Repository\Sport\CotisationJoueurRepository $cotisationRepository,
        \App\Repository\Sport\JoueurRepository $joueurRepository,
        \App\Repository\Sport\ParentJoueurRepository $parentJoueurRepository,
        \App\Repository\Sport\BilanCompetenceRepository $bilanRepo,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $joueur);

        // V1.4a — Candidats au lien PIRB : Users du club non encore liés
        // Affichés uniquement aux CLUB_STAFF (les autres ne voient même pas la section).
        $candidatsLinkUser = [];
        if ($this->isGranted(ClubVoter::CLUB_STAFF, $joueur)) {
            $rechercheUser = trim((string) $request->query->get('q_user', ''));
            $candidatsLinkUser = $joueurRepository->findCandidatsLinkUser($joueur, $rechercheUser ?: null);
        }

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
        //
        // Agrégat global (toutes équipes confondues) — toujours calculé.
        $performancesSaison    = $evaluationCalculator->moyennesSaison($joueur, $saison);
        $evaluationsRecentes   = $evaluationMatchRepository->evaluationsRecentes($joueur, 5);

        // ====================================================================
        // Perfs multi-équipes : si la joueuse joue dans 2+ équipes, on calcule
        // les stats PAR équipe pour le dropdown de filtre dans le template.
        // Équipe principale + affectations actives (surclassement, réserve, etc.)
        // ====================================================================
        $toutesEquipesJoueur = [];
        if ($joueur->getEquipe() !== null) {
            $toutesEquipesJoueur[] = $joueur->getEquipe();
        }
        foreach ($joueur->getAffectations() as $aff) {
            if ($aff->isActif() && $aff->getEquipe() !== null) {
                // Dédup au cas où la même équipe serait déjà présente
                $dejaPresente = false;
                foreach ($toutesEquipesJoueur as $e) {
                    if ($e->getId() === $aff->getEquipe()->getId()) {
                        $dejaPresente = true;
                        break;
                    }
                }
                if (!$dejaPresente) {
                    $toutesEquipesJoueur[] = $aff->getEquipe();
                }
            }
        }
        // Ne construire le tableau par-équipe que si vraiment multi-équipes
        // (inutile de multiplier les requêtes pour un cas mono-équipe normal)
        $performancesParEquipe = [];
        if (count($toutesEquipesJoueur) > 1) {
            foreach ($toutesEquipesJoueur as $equipeItem) {
                $perfEquipe  = $evaluationCalculator->moyennesSaison($joueur, $saison, $equipeItem);
                $evalsEquipe = $evaluationMatchRepository->evaluationsRecentes($joueur, 5, $equipeItem);
                $performancesParEquipe[] = [
                    'equipe'                => $equipeItem,
                    'performances'          => $perfEquipe,
                    'evaluations_recentes'  => $evalsEquipe,
                ];
            }
        }

        // ====================================================================
        // V1.6.1 — Affectations multi-équipes (surclassement / doublage / réserve)
        // ====================================================================
        // Liste des équipes disponibles pour le select du formulaire "Ajouter
        // une affectation". On exclut l'équipe principale ET celles déjà
        // affectées (sinon UNIQUE constraint viol).
        $equipePrincipaleId = $joueur->getEquipe()?->getId();
        $equipesDejaAffectees = [];
        foreach ($joueur->getAffectations() as $aff) {
            $equipesDejaAffectees[] = $aff->getEquipe()?->getId();
        }
        $equipesDisponibles = $this->equipeRepository->createQueryBuilder('e')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->setParameter('club', $joueur->getClub())
            ->orderBy('e.categorie', 'ASC')
            ->addOrderBy('e.nom', 'ASC')
            ->getQuery()->getResult();
        $equipesDisponibles = array_filter($equipesDisponibles, function(Equipe $e) use ($equipePrincipaleId, $equipesDejaAffectees) {
            if ($equipePrincipaleId !== null && $e->getId() === $equipePrincipaleId) return false;
            if (in_array($e->getId(), $equipesDejaAffectees, true)) return false;
            return true;
        });

        // Types d'affectation pour le select (hors principale — gérée séparément)
        $typesAffectationDisponibles = [];
        foreach (JoueurEquipe::TYPES as $t) {
            if ($t === JoueurEquipe::TYPE_PRINCIPALE) continue;
            $typesAffectationDisponibles[$t] = JoueurEquipe::TYPE_LABELS[$t] ?? $t;
        }

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
            'performances_saison'      => $performancesSaison,
            'evaluations_recentes'     => $evaluationsRecentes,
            'performances_par_equipe'  => $performancesParEquipe,
            // D.3.2 — Section "Ma cotisation" sur la fiche
            'cotisation_courante' => $cotisationCourante,
            'is_self'             => $isSelf,
            'is_tresorier'        => $isTresorier,
            // V1.4a — Section "Compte PIRB lié" (visible CLUB_STAFF)
            'candidats_link_user' => $candidatsLinkUser,
            'recherche_user'      => trim((string) $request->query->get('q_user', '')),
            // V1.6.1 — Affectations multi-équipes
            'equipes_disponibles'           => array_values($equipesDisponibles),
            'types_affectation_disponibles' => $typesAffectationDisponibles,
            'type_labels'                   => JoueurEquipe::TYPE_LABELS,
            'type_couleurs'                 => JoueurEquipe::TYPE_COULEURS,
            'saison_courante'               => $saison,
            // Bilan le plus récent (toutes saisons, tous statuts) — pour le bloc bilan profil
            'bilan_recent'                  => $bilanRepo->findByJoueur($joueur)[0] ?? null,
            // Liens Parents — tous les ParentJoueur de cette joueuse (actifs + pending)
            'parent_joueurs'                => $parentJoueurRepository->findByJoueur($joueur),
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

    /**
     * V1.4a — Lie un User PIRB à cette fiche Joueur.
     * Accessible CLUB_STAFF (DIRIGEANT + COACH + STAFF).
     * Vérifie que l'user n'est pas déjà lié à une autre fiche du même club.
     */
    #[Route('/joueuses/{id}/lier-user', name: 'manager_joueur_link_user', methods: ['POST'])]
    public function linkUser(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if (!$this->isCsrfTokenValid('link_user_joueur_' . $joueur->getId(), (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $userId = (int) $request->request->get('user_id', 0);
        if ($userId <= 0) {
            $this->addFlash('error', 'Aucun utilisateur sélectionné.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $user = $this->em->getRepository(\App\Entity\Core\User::class)->find($userId);
        if ($user === null) {
            $this->addFlash('error', 'Utilisateur introuvable.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // Anti-doublon : vérifier que cet user n'est pas DÉJÀ lié à une autre
        // fiche Joueur du même club (au cas où le filtre côté repo a foiré).
        $autreJoueurLie = $this->em->getRepository(Joueur::class)->findOneBy([
            'user' => $user,
            'club' => $joueur->getClub(),
        ]);
        if ($autreJoueurLie !== null && $autreJoueurLie->getId() !== $joueur->getId()) {
            $this->addFlash('error', sprintf(
                '%s est déjà lié à la fiche de %s. Délie-le d\'abord ou choisis un autre user.',
                $user->getPrenom() . ' ' . $user->getNom(),
                $autreJoueurLie->getNomComplet()
            ));
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $joueur->setUser($user);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✅ Compte PIRB lié : %s %s (%s).',
            $user->getPrenom(),
            $user->getNom(),
            $user->getEmail()
        ));
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }

    /**
     * V1.4a — Délie le User PIRB de cette fiche Joueur.
     * Utile en cas d'erreur de lien ou de départ du joueur.
     */
    #[Route('/joueuses/{id}/delier-user', name: 'manager_joueur_unlink_user', methods: ['POST'])]
    public function unlinkUser(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if (!$this->isCsrfTokenValid('unlink_user_joueur_' . $joueur->getId(), (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $joueur->setUser(null);
        $this->em->flush();

        $this->addFlash('success', '🔓 Compte PIRB délié de cette fiche.');
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }

    /**
     * V1.6.1 — Ajoute une affectation équipe (surclassement / doublage / réserve).
     *
     * L'équipe principale est gérée via Joueur.equipe (champ historique) + sa
     * JoueurEquipe principale auto-créée par la migration V1.6. Cette route
     * sert UNIQUEMENT à ajouter une affectation secondaire (multi-équipes).
     *
     * Garde-fous métier :
     *   - L'équipe sélectionnée doit être du même club (multi-tenant)
     *   - Doit être différente de l'équipe principale (sinon doublon non-sens)
     *   - Doit être différente d'une affectation existante (UNIQUE constraint)
     *   - Type doit être valide (pas 'principale' — on ne peut pas avoir 2 principales)
     */
    #[Route('/joueuses/{id}/affectations/ajouter', name: 'manager_joueur_affectation_add', methods: ['POST'])]
    public function affectationAdd(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if (!$this->isCsrfTokenValid('add_affectation_joueur_' . $joueur->getId(), (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // === Inputs ===
        $equipeId = (int) $request->request->get('equipe_id', 0);
        $type = (string) $request->request->get('type', '');
        $saison = trim((string) $request->request->get('saison', '2025-2026'));
        $notes = trim((string) $request->request->get('notes', ''));

        // === Validation type ===
        if (!in_array($type, JoueurEquipe::TYPES, true) || $type === JoueurEquipe::TYPE_PRINCIPALE) {
            $this->addFlash('error', 'Type d\'affectation invalide (l\'équipe principale est gérée séparément).');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // === Validation saison (format ISO 2025-2026) ===
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            $this->addFlash('error', 'Format saison invalide (attendu : "2025-2026").');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // === Validation équipe : doit exister et être du même club ===
        $equipe = $this->equipeRepository->find($equipeId);
        if (!$equipe || $equipe->getClub()->getId() !== $joueur->getClub()->getId()) {
            $this->addFlash('error', 'Équipe invalide ou d\'un autre club.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // === Anti-doublon : équipe principale ===
        if ($joueur->getEquipe() && $joueur->getEquipe()->getId() === $equipe->getId()) {
            $this->addFlash('error', sprintf(
                'Cette joueuse est déjà dans %s (équipe principale). Inutile de l\'ajouter en %s.',
                $equipe->getNom(),
                JoueurEquipe::TYPE_LABELS[$type]
            ));
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        // === Anti-doublon : affectation existante (UNIQUE constraint en base mais on check avant pour msg propre) ===
        foreach ($joueur->getAffectations() as $aff) {
            if ($aff->getEquipe() === $equipe && $aff->getSaison() === $saison) {
                $this->addFlash('error', sprintf(
                    'Cette joueuse a déjà une affectation à %s pour la saison %s.',
                    $equipe->getNom(),
                    $saison
                ));
                return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
            }
        }

        // === Création ===
        $affectation = new JoueurEquipe();
        $affectation->setJoueur($joueur);
        $affectation->setEquipe($equipe);
        $affectation->setType($type);
        $affectation->setSaison($saison);
        $affectation->setActif(true);
        if ($notes !== '') {
            $affectation->setNotes($notes);
        }

        $this->em->persist($affectation);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✅ %s ajouté : %s dans "%s" (%s).',
            JoueurEquipe::TYPE_LABELS[$type],
            $joueur->getNomComplet(),
            $equipe->getNom(),
            $saison
        ));
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }

    /**
     * V1.6.1 — Supprime une affectation équipe.
     *
     * Garde-fou : on REFUSE de supprimer l'affectation principale (sinon la
     * joueuse n'a plus d'équipe de référence). Pour changer l'équipe principale,
     * passer par la modification de Joueur.equipe.
     */
    #[Route('/joueuses/{id}/affectations/{affId}/supprimer', name: 'manager_joueur_affectation_remove', methods: ['POST'])]
    public function affectationRemove(Request $request, Joueur $joueur, int $affId): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if (!$this->isCsrfTokenValid('remove_affectation_' . $affId, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $affectation = $this->em->getRepository(JoueurEquipe::class)->find($affId);
        if (!$affectation || $affectation->getJoueur() !== $joueur) {
            $this->addFlash('error', 'Affectation introuvable.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        if ($affectation->isPrincipale()) {
            $this->addFlash('error', 'Impossible de supprimer l\'affectation principale. Modifie l\'équipe de la joueuse à la place.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $equipeNom = $affectation->getEquipe()->getNom();
        $typeLabel = JoueurEquipe::TYPE_LABELS[$affectation->getType()] ?? $affectation->getType();

        $this->em->remove($affectation);
        $this->em->flush();

        $this->addFlash('success', sprintf('🗑️ %s retiré de "%s".', $typeLabel, $equipeNom));
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }
}
