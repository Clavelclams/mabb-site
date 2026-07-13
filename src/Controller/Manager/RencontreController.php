<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Mission;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\RencontreRole;
use App\Form\Manager\RencontreType;
use App\Gamification\BadgeChecker;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\RencontreRepository;
use App\Repository\Sport\RencontreRoleRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\RencontrePdfUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RencontreController — gestion des rencontres (matchs).
 *
 * Renommé "Rencontre" car "match" est un mot-clé PHP 8+ (expression match).
 *
 * Workflow statut :
 *   brouillon  → le coach prépare le match avant qu'il ait lieu
 *   validé     → après le match, score saisi, feuille de match validée
 *   verrouillé → un dirigeant fige la feuille de match (anti-triche)
 *
 * Une rencontre verrouillée ne peut plus être modifiée que par un admin
 * (et toute modification est tracée — sera ajouté avec l'audit log).
 */
class RencontreController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly RencontreRepository $rencontreRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly EntityManagerInterface $em,
        private readonly BadgeChecker $badgeChecker,
        private readonly RencontreRoleRepository $rencontreRoleRepository,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly \App\Repository\Sport\ConvocationRepository $convocationRepository,
        private readonly \App\Service\SaisonService $saisonService,
    ) {}

    /**
     * Liste des rencontres avec filtres équipe + période.
     */
    #[Route('/rencontres', name: 'manager_rencontre_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        $equipeFiltre = null;
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipeFiltre = $this->equipeRepository->find($equipeId);
            if (!$equipeFiltre || $equipeFiltre->getClub()->getId() !== $club->getId()) {
                $this->addFlash('error', 'Équipe invalide.');
                return $this->redirectToRoute('manager_rencontre_index');
            }
        }

        $periode = $request->query->get('periode', 'a_venir');
        $now = new \DateTimeImmutable();

        $qb = $this->rencontreRepository->createQueryBuilder('r')
            ->where('r.club = :club')
            ->setParameter('club', $club);

        if ($equipeFiltre) {
            $qb->andWhere('r.equipe = :equipe')
               ->setParameter('equipe', $equipeFiltre);
        }

        // V2.1c — Filtre archivées : par défaut on cache les archivées.
        // Toggle "Voir aussi les archivées" via ?archive=1
        $voirArchive = $request->query->getBoolean('archive');
        if (!$voirArchive) {
            $qb->andWhere('r.statut != :statut_archive')
               ->setParameter('statut_archive', Rencontre::STATUT_ARCHIVE);
        }

        switch ($periode) {
            case 'a_venir':
                $qb->andWhere('r.date >= :now')
                   ->setParameter('now', $now)
                   ->orderBy('r.date', 'ASC');
                break;
            case 'passees':
                $qb->andWhere('r.date < :now')
                   ->setParameter('now', $now)
                   ->orderBy('r.date', 'DESC');
                break;
            default:
                $qb->orderBy('r.date', 'DESC');
        }

        $rencontres = $qb->getQuery()->getResult();

        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        // Comptage des rôles bénévoles remplis par rencontre (1 requête SQL groupée,
        // pas de N+1). Passé au template pour afficher le badge "X/7 postes".
        $nbRolesParRencontre = $this->rencontreRoleRepository->countByRencontres($rencontres);

        return $this->render('manager/rencontre/index.html.twig', [
            'rencontres'          => $rencontres,
            'equipes'             => $equipes,
            'equipe_filtre'       => $equipeFiltre,
            'periode'             => $periode,
            'voir_archive'        => $voirArchive,
            'club'                => $club,
            'nb_roles_rencontres' => $nbRolesParRencontre,
        ]);
    }

    /**
     * Vue détail d'une rencontre + saisie de score si la rencontre est passée.
     */
    #[Route('/rencontres/{id}', name: 'manager_rencontre_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $rencontre);

        // [V2.2] Charger les joueuses éphémères pour la modale et l'affichage
        $joueusesEphemeres = [];
        if ($rencontre->isNonOfficielle()) {
            $joueusesEphemeres = $this->joueurRepository->findBy(
                ['rencontreOrigine' => $rencontre],
                ['equipeEphemere' => 'ASC', 'numeroMaillot' => 'ASC', 'nom' => 'ASC']
            );
        }

        // [13/07/2026] CONVOCATIONS — le module manquait complètement : la table
        // `convocation` n'était écrite nulle part, donc l'espace joueuse (web ET
        // app) lisait une table vide. On fournit ici l'effectif de l'équipe pour
        // la saison de la rencontre, et les convocations déjà posées (avec leur
        // réponse), pour que le coach coche et voie qui a répondu quoi.
        $effectif = [];
        $convocations = [];
        $equipe = $rencontre->getEquipe();
        if ($equipe !== null) {
            $saison = $rencontre->getSaison() ?? $this->saisonService->getSaisonCourante();
            $effectif = $this->joueurRepository->findByEquipeAffectation($equipe, $saison);

            foreach ($this->convocationRepository->findBy(['rencontre' => $rencontre]) as $c) {
                $idJoueur = $c->getJoueur()?->getId();
                if ($idJoueur !== null) {
                    $convocations[$idJoueur] = $c; // indexé par joueur : le template lit direct
                }
            }
        }

        return $this->render('manager/rencontre/show.html.twig', [
            'rencontre'          => $rencontre,
            'joueuses_ephemeres' => $joueusesEphemeres,
            'effectif'           => $effectif,
            'convocations'       => $convocations,
        ]);
    }

    /**
     * Création d'une nouvelle rencontre.
     */
    #[Route('/rencontres/nouvelle', name: 'manager_rencontre_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $rencontre = new Rencontre();
        $rencontre->setClub($club);

        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            if ($equipe && $equipe->getClub()->getId() === $club->getId()) {
                $rencontre->setEquipe($equipe);
            }
        }

        $form = $this->createForm(RencontreType::class, $rencontre, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // [V2.2] Les joueuses éphémères ne sont plus saisies dans le form de création.
            // Elles s'ajoutent via la modale "Ajouter joueuse rapide" sur la page détail
            // rencontre (route manager_rencontre_joueur_rapide). Workflow plus intuitif :
            // on crée d'abord la rencontre, puis on ajoute les joueuses.
            $this->em->persist($rencontre);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Rencontre contre %s créée.%s',
                $rencontre->getAdversaire(),
                $rencontre->isExhibition()
                    ? ' Ajoute maintenant les joueuses éphémères avec le bouton "Joueuse rapide".'
                    : ''
            ));
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        return $this->render('manager/rencontre/new.html.twig', [
            'form'      => $form,
            'rencontre' => $rencontre,
            'club'      => $club,
        ]);
    }

    /**
     * [B31 12/06/2026] Parse le texte libre des joueuses éphémères en JSON.
     * Chaque ligne devient un objet {prenom, nom, role}.
     * Format toléré :
     *   "Fatou MAMAN (sparring)"  → {prenom: "Fatou", nom: "MAMAN", role: "sparring"}
     *   "Clément DIRIGEANT"        → {prenom: "Clément", nom: "DIRIGEANT", role: null}
     */
    private function parseJoueursEphemeres(string $texte): ?array
    {
        $texte = trim($texte);
        if ($texte === '') return null;

        $result = [];
        $lignes = preg_split('/\r\n|\r|\n/', $texte) ?: [];

        foreach ($lignes as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') continue;

            $role = null;
            if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/', $ligne, $m)) {
                $ligne = trim($m[1]);
                $role = trim($m[2]);
            }

            $parts = preg_split('/\s+/', $ligne, 2) ?: [];
            if (count($parts) < 2) {
                // 1 seul mot = pseudo
                $result[] = ['prenom' => $parts[0] ?? '', 'nom' => '', 'role' => $role, 'pseudo' => true];
            } else {
                $result[] = ['prenom' => $parts[0], 'nom' => $parts[1], 'role' => $role, 'pseudo' => false];
            }
        }

        return empty($result) ? null : $result;
    }

    /**
     * Modification d'une rencontre.
     *
     * SÉCURITÉ : si la rencontre est verrouillée, seul un admin peut modifier.
     * On bloque l'accès en avance pour ne pas afficher le formulaire d'édition
     * à un coach sur une rencontre verrouillée.
     */
    #[Route('/rencontres/{id}/modifier', name: 'manager_rencontre_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // Une rencontre verrouillée ne peut être modifiée que par un admin
        if ($rencontre->isVerrouillee()) {
            $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
            $this->addFlash('warning', 'Cette rencontre est verrouillée. Seul un dirigeant peut la modifier.');
        }

        $form = $this->createForm(RencontreType::class, $rencontre, ['club' => $rencontre->getClub()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Rencontre mise à jour.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        return $this->render('manager/rencontre/edit.html.twig', [
            'form'      => $form,
            'rencontre' => $rencontre,
        ]);
    }

    /**
     * Changement de statut d'une rencontre (brouillon → validé → verrouillé).
     *
     * Workflow strict :
     *   - "valider" : seul un coach/staff peut passer de brouillon à validé
     *   - "verrouiller" : seul un dirigeant peut passer de validé à verrouillé
     *   - "dever" : seul un dirigeant peut revenir de verrouillé à validé
     *
     * Cette progression empêche un coach de modifier en douce une rencontre
     * déjà publiée. C'est ce qu'on défendra au jury (workflow métier).
     */
    #[Route('/rencontres/{id}/statut', name: 'manager_rencontre_statut', methods: ['POST'])]
    public function changerStatut(Request $request, Rencontre $rencontre): Response
    {
        $action = $request->request->get('action');
        $token = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('statut_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        switch ($action) {
            case 'valider':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VALIDE);
                $this->addFlash('success', 'Rencontre validée. La feuille de match est officialisée.');
                break;

            case 'verrouiller':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VERROUILLE);
                $this->addFlash('success', 'Rencontre verrouillée. Plus de modification possible sauf par un dirigeant.');
                break;

            case 'dever':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VALIDE);
                $this->addFlash('success', 'Rencontre déverrouillée. Édition à nouveau possible.');
                break;

            default:
                $this->addFlash('error', 'Action invalide.');
                return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $this->em->flush();
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Suppression d'une rencontre.
     *
     * Interdit si la rencontre est verrouillée (sauf admin).
     * Une rencontre passée avec score saisi ne devrait pas être supprimée
     * (impact sur les stats) — on conseille l'archive plutôt.
     */
    #[Route('/rencontres/{id}/supprimer', name: 'manager_rencontre_delete', methods: ['POST'])]
    public function delete(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        $this->em->remove($rencontre);
        $this->em->flush();

        $this->addFlash('success', 'Rencontre supprimée.');
        return $this->redirectToRoute('manager_rencontre_index');
    }

    /**
     * ARCHIVER une rencontre (V2.1c).
     * Réversible. La rencontre disparaît des listes par défaut mais reste en BDD.
     * Permission CLUB_STAFF (moins strict que delete).
     */
    #[Route('/rencontres/{id}/archiver', name: 'manager_rencontre_archiver', methods: ['POST'])]
    public function archiver(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('archiver_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        if ($rencontre->isArchivee()) {
            $this->addFlash('info', 'Cette rencontre est déjà archivée.');
        } else {
            $rencontre->setStatut(Rencontre::STATUT_ARCHIVE);
            $this->em->flush();
            $this->addFlash('success', 'Rencontre archivée. Visible via "Voir les archivées".');
        }

        return $this->redirectToRoute('manager_rencontre_index');
    }

    /**
     * Archivage GROUPÉ depuis la liste — sélection multiple.
     * Body POST classique : { rencontres_ids: [3, 7, 12], _token: '...' }
     *
     * Action sûre : on ne touche QUE les rencontres du club actif (anti-IDOR).
     * Les IDs hors club sont silencieusement ignorés.
     */
    #[Route('/rencontres/bulk-archiver', name: 'manager_rencontre_bulk_archiver', methods: ['POST'])]
    public function bulkArchiver(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('error', 'Aucun club actif.');
            return $this->redirectToRoute('manager_rencontre_index');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('bulk_archiver_rencontres', $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        $ids = (array) $request->request->all('rencontres_ids');
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            $this->addFlash('warning', 'Aucune rencontre sélectionnée.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        $rencontres = $this->rencontreRepository->createQueryBuilder('r')
            ->where('r.club = :club')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('club', $club)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $nbArchivees = 0;
        $nbSkipped = 0;
        foreach ($rencontres as $r) {
            /** @var Rencontre $r */
            if ($r->isArchivee()) {
                $nbSkipped++;
                continue;
            }
            $r->setStatut(Rencontre::STATUT_ARCHIVE);
            $nbArchivees++;
        }
        $this->em->flush();

        if ($nbArchivees > 0) {
            $this->addFlash('success', sprintf(
                '%d rencontre%s archivée%s.',
                $nbArchivees,
                $nbArchivees > 1 ? 's' : '',
                $nbArchivees > 1 ? 's' : ''
            ));
        }
        if ($nbSkipped > 0) {
            $this->addFlash('info', sprintf(
                '%d déjà archivée%s — ignorée%s.',
                $nbSkipped,
                $nbSkipped > 1 ? 's' : '',
                $nbSkipped > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('manager_rencontre_index');
    }

    /**
     * DÉSARCHIVER : remet la rencontre en BROUILLON (statut neutre).
     */
    #[Route('/rencontres/{id}/desarchiver', name: 'manager_rencontre_desarchiver', methods: ['POST'])]
    public function desarchiver(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('desarchiver_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        if (!$rencontre->isArchivee()) {
            $this->addFlash('info', 'Cette rencontre n\'est pas archivée.');
        } else {
            // On la repasse en BROUILLON par défaut (l'user pourra la re-valider si besoin)
            $rencontre->setStatut(Rencontre::STATUT_BROUILLON);
            $this->em->flush();
            $this->addFlash('success', 'Rencontre désarchivée (statut : Brouillon).');
        }

        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    // ════════════════════════════════════════════════════════════════════
    // RÔLES OFFICIELS d'une rencontre (arbitres, marqueur, chrono, e-marque,
    // resp salle, stats live). Inscription par les User, validation par
    // le staff après match → déclenche Mission gamification axe C.
    // ════════════════════════════════════════════════════════════════════

    /**
     * Inscription d'un user à un rôle officiel.
     * URL : POST /rencontres/{id}/role/{role}/sinscrire
     */
    #[Route('/rencontres/{id}/role/{role}/sinscrire', name: 'manager_rencontre_role_sinscrire', methods: ['POST'])]
    public function sInscrireRole(Request $request, Rencontre $rencontre, string $role): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $rencontre);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }
        if (!in_array($role, RencontreRole::ROLES, true)) {
            $this->addFlash('error', 'Rôle invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('role_' . $rencontre->getId() . '_' . $role, $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // [B22 — 12/06/2026] Inscriptions closes une fois le match passé (date + 4h).
        // Un STAFF peut quand même corriger après-coup pour régulariser une oubli (ex: mission gamif).
        if ($rencontre->isPassee() && !$this->isGranted(ClubVoter::CLUB_STAFF, $rencontre)) {
            $this->addFlash('warning', 'Match terminé : les inscriptions aux rôles officiels sont closes.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // Blocage des rôles ARBITRE_x si la FFBB a désigné un officiel
        $estArbitre = in_array($role, [RencontreRole::ROLE_ARBITRE_1, RencontreRole::ROLE_ARBITRE_2], true);
        if ($estArbitre && !$rencontre->peutRecevoirArbitreBenevole()) {
            $this->addFlash('warning', 'Arbitre FFBB déjà désigné, inscription bénévole impossible sur ce rôle.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // Refus si déjà un user inscrit sur ce rôle
        if ($rencontre->getRoleParCode($role) !== null) {
            $this->addFlash('info', sprintf('Le rôle "%s" est déjà pris.', RencontreRole::ROLE_LIBELLES[$role]));
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $rr = new RencontreRole();
        $rr->setRencontre($rencontre);
        $rr->setUser($user);
        $rr->setRole($role);
        $this->em->persist($rr);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Tu es inscrit comme "%s" pour ce match. Merci !',
            RencontreRole::ROLE_LIBELLES[$role]
        ));
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Désinscription d'un user de son rôle. Le user lui-même ou un staff.
     */
    #[Route('/rencontres/{id}/role/{role}/desinscrire', name: 'manager_rencontre_role_desinscrire', methods: ['POST'])]
    public function seDesinscrireRole(Request $request, Rencontre $rencontre, string $role): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $rencontre);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }
        if (!in_array($role, RencontreRole::ROLES, true)) {
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('desinscrire_role_' . $rencontre->getId() . '_' . $role, $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $rr = $rencontre->getRoleParCode($role);
        if (!$rr) {
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $estStaff = $this->isGranted(ClubVoter::CLUB_STAFF, $rencontre);
        $estLeUser = $rr->getUser() && $rr->getUser()->getId() === $user->getId();
        if (!$estStaff && !$estLeUser) {
            $this->addFlash('error', 'Action non autorisée.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // [B22 — 12/06/2026] Désinscription bloquée post-match sauf STAFF
        // (qui peut corriger en cas d'erreur — ex: remplacer un nom)
        if ($rencontre->isPassee() && !$estStaff) {
            $this->addFlash('warning', 'Match terminé : seul un staff peut modifier les rôles maintenant.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $this->em->remove($rr);
        $this->em->flush();
        $this->addFlash('success', 'Désinscription effectuée.');
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Staff valide que le user a bien tenu son rôle pendant le match.
     * Crée une Mission de gamification (axe C) selon le mapping RencontreRole::MAPPING_MISSION.
     */
    #[Route('/rencontres/{id}/role/{role}/valider', name: 'manager_rencontre_role_valider', methods: ['POST'])]
    public function validerRole(Request $request, Rencontre $rencontre, string $role): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);
        if (!in_array($role, RencontreRole::ROLES, true)) {
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('valider_role_' . $rencontre->getId() . '_' . $role, $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $rr = $rencontre->getRoleParCode($role);
        if (!$rr) {
            $this->addFlash('error', 'Aucun inscrit sur ce rôle.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }
        if ($rr->isPresent()) {
            $this->addFlash('info', 'Présence déjà validée.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $rr->setPresent(true);

        // Création de la Mission gamification si le User a un Joueur lié au club
        $missionCree = false;
        $joueur = $this->em->getRepository(Joueur::class)->findOneBy([
            'user' => $rr->getUser(),
            'club' => $rencontre->getClub(),
        ]);
        if ($joueur !== null) {
            $missionType = RencontreRole::MAPPING_MISSION[$role] ?? Mission::TYPE_AUTRE;
            $mission = new Mission();
            $mission->setClub($rencontre->getClub());
            $mission->setJoueur($joueur);
            $mission->setType($missionType);
            $mission->setDate(\DateTimeImmutable::createFromInterface($rencontre->getDate()));
            $mission->setDescription(sprintf(
                '%s — match %s vs %s',
                RencontreRole::ROLE_LIBELLES[$role],
                $rencontre->getEquipe()->getNom(),
                $rencontre->getAdversaire()
            ));
            $mission->setValidePar($this->getUser() instanceof User ? $this->getUser() : null);
            $this->em->persist($mission);
            $missionCree = true;
        }

        $this->em->flush();

        $nbBadges = 0;
        if ($missionCree && $joueur !== null) {
            $nouveaux = $this->badgeChecker->syncBadges($joueur);
            $nbBadges = count($nouveaux);
        }

        $this->addFlash('success', sprintf(
            '%s validé(e).%s%s',
            RencontreRole::ROLE_LIBELLES[$role],
            $missionCree ? ' 🎯 Mission créée.' : '',
            $nbBadges > 0 ? sprintf(' 🏆 %d badge(s) débloqué(s) !', $nbBadges) : ''
        ));
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    // ====================================================================
    // GESTION DES PDFs FFBB OFFICIELS (Étape C — Stats FFBB)
    //
    // 3 types supportés : resume | feuille | positions
    // Requirements regex pour bloquer les valeurs hors whitelist au niveau routing
    // (défense en profondeur — le service valide aussi côté code).
    // ====================================================================

    /**
     * Upload un PDF FFBB pour une rencontre (résumé / feuille / positions tirs).
     *
     *   POST manager.mabb.fr/rencontres/{id}/pdfs/{type}
     *
     * Sécurité :
     *   - CLUB_STAFF requis sur la rencontre
     *   - CSRF nominatif lié à la rencontre + type
     *   - Validation MIME application/pdf stricte par le service
     */
    #[Route(
        '/rencontres/{id}/pdfs/{type}',
        name: 'manager_rencontre_pdf_upload',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'type' => 'resume|feuille|positions']
    )]
    public function uploadPdf(
        Request $request,
        Rencontre $rencontre,
        string $type,
        RencontrePdfUploader $uploader,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('upload_pdf_' . $type . '_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('pdf');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        try {
            $filename = $uploader->upload($file, $rencontre, $type);
            $rencontre->setPdfPath($type, $filename);
            $this->em->flush();

            $libelles = ['resume' => 'résumé', 'feuille' => 'feuille de match', 'positions' => 'positions de tirs'];
            $this->addFlash('success', sprintf('PDF %s mis à jour.', $libelles[$type]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (FileException $e) {
            $this->addFlash('error', 'Impossible d\'enregistrer le fichier sur le serveur.');
        }

        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Suppression d'un PDF FFBB.
     *
     *   POST manager.mabb.fr/rencontres/{id}/pdfs/{type}/supprimer
     */
    #[Route(
        '/rencontres/{id}/pdfs/{type}/supprimer',
        name: 'manager_rencontre_pdf_delete',
        methods: ['POST'],
        requirements: ['id' => '\d+', 'type' => 'resume|feuille|positions']
    )]
    public function deletePdf(
        Request $request,
        Rencontre $rencontre,
        string $type,
        RencontrePdfUploader $uploader,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_pdf_' . $type . '_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        if ($rencontre->getPdfPath($type) === null) {
            $this->addFlash('info', 'Aucun PDF à supprimer pour ce type.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // Supprime le fichier physique D'ABORD, puis met le path à null en BDD
        $uploader->delete($rencontre, $type);
        $rencontre->setPdfPath($type, null);
        $this->em->flush();

        $libelles = ['resume' => 'résumé', 'feuille' => 'feuille de match', 'positions' => 'positions de tirs'];
        $this->addFlash('success', sprintf('PDF %s supprimé.', $libelles[$type]));

        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Sert un PDF FFBB en streaming avec contrôle multi-tenant.
     *
     *   GET manager.mabb.fr/rencontres/{id}/pdfs/{type}/voir
     *
     * POURQUOI UNE ROUTE ET PAS UN LIEN DIRECT public/uploads/... :
     *   - Les PDFs contiennent des données semi-confidentielles (noms,
     *     scores, fautes) qui ne doivent pas être accessibles à un autre club.
     *   - Servir via une route Symfony permet d'appliquer le ClubVoter.
     *   - Le filename uniqid limite la fuite mais n'est pas une vraie sécurité.
     *
     * Le PDF est servi inline (affichable dans un iframe pour le split-screen
     * de saisie des évals).
     */
    #[Route(
        '/rencontres/{id}/pdfs/{type}/voir',
        name: 'manager_rencontre_pdf_serve',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'type' => 'resume|feuille|positions']
    )]
    public function servePdf(
        Rencontre $rencontre,
        string $type,
        RencontrePdfUploader $uploader,
    ): Response {
        // CLUB_MEMBER suffit pour consulter (lecture seule) — pas besoin d'être staff
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $rencontre);

        $absolutePath = $uploader->getAbsolutePath($rencontre, $type);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('PDF introuvable.');
        }

        // Streaming en mémoire-friendly via BinaryFileResponse
        // disposition INLINE pour affichage dans un iframe (pas de download)
        $libelles = ['resume' => 'resume', 'feuille' => 'feuille-de-match', 'positions' => 'positions-de-tirs'];
        $nomTelechargement = sprintf(
            'mabb-%s-%s-vs-%s.pdf',
            $libelles[$type],
            $rencontre->getDate()?->format('Y-m-d') ?? 'rencontre',
            preg_replace('/[^a-z0-9-]+/i', '-', $rencontre->getAdversaire() ?? 'adversaire')
        );

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $nomTelechargement
        );
        return $response;
    }

    /**
     * [B22b-bis 14/06/2026] Validation manuelle Stats FFBB par le coach/staff.
     *
     * Le coach déclare avoir comparé sa saisie EvaluationMatch avec le PDF
     * resume FFBB officiel. Trace tracée pour affichage côté PIRB joueuse.
     *
     * Pourquoi pas un parser PDF :
     *   Constat 14/06 : les PDFs FFBB sont des rendus VISUELS scannés
     *   (smalot/pdfparser sort 0 caractère). OCR sur OVH mutu non viable.
     *   Pivot vers check humain qui est de toute façon plus fiable (le coach
     *   sait reconnaître une erreur de saisie FFBB du marqueur).
     *
     * Sécurité : CLUB_STAFF requis (Coach, Dirigeant, Staff, Trésorier).
     * Idempotent : re-validation possible (mise à jour validatedAt + note).
     */
    #[Route(
        '/rencontres/{id}/valider-stats-ffbb',
        name: 'manager_rencontre_valider_stats_ffbb',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function validerStatsFfbb(Rencontre $rencontre, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // CSRF protection
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('valider_stats_ffbb_' . $rencontre->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // Pré-requis : un PDF resume FFBB doit être uploadé
        if ($rencontre->getResumePath() === null) {
            $this->addFlash('warning', 'Aucun PDF FFBB resume uploadé. Upload-le d\'abord.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // Pré-requis : le match doit être passé (cohérence métier)
        if (!$rencontre->isPassee()) {
            $this->addFlash('warning', 'Tu ne peux valider les stats FFBB que pour un match déjà joué.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $note = trim((string) $request->request->get('validation_note', ''));

        $rencontre->setFfbbStatsValidatedAt(new \DateTimeImmutable());
        $rencontre->setFfbbStatsValidatedBy($user);
        $rencontre->setFfbbStatsValidationNote($note !== '' ? $note : null);

        $this->em->flush();

        $this->addFlash('success', '✓ Stats FFBB validées. Les joueuses verront le badge officiel.');
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * [B22b-bis 14/06/2026] Annulation de la validation (correction si erreur).
     * Même permission CLUB_STAFF — réversible.
     */
    #[Route(
        '/rencontres/{id}/invalider-stats-ffbb',
        name: 'manager_rencontre_invalider_stats_ffbb',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function invaliderStatsFfbb(Rencontre $rencontre, Request $request): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('invalider_stats_ffbb_' . $rencontre->getId(), $token)) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $rencontre->setFfbbStatsValidatedAt(null);
        $rencontre->setFfbbStatsValidatedBy(null);
        $rencontre->setFfbbStatsValidationNote(null);
        $this->em->flush();

        $this->addFlash('info', 'Validation Stats FFBB annulée.');
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    // =========================================================================
    // [V2.2 — 25/06/2026] JOUEUSES ÉPHÉMÈRES
    // =========================================================================

    /**
     * Création d'une joueuse éphémère pour une rencontre (AJAX JSON).
     *
     * POST /rencontres/{id}/joueur-rapide
     * Body JSON :
     * {
     *   "prenom": "Fatou",
     *   "nom": "Diallo",
     *   "numero": 7,
     *   "equipeEphemere": null,           // null = notre équipe, string = adversaire
     *   "couleurMaillot": "#ef4444"       // optionnel
     * }
     *
     * Réponse JSON 201 :
     * { "id": 42, "nomComplet": "Fatou Diallo", "numero": 7, "equipeEphemere": null, "couleur": "#ef4444" }
     *
     * Sécurité :
     *   - CLUB_STAFF requis
     *   - CSRF token via header X-CSRF-Token (token_id: "joueur_rapide_{rencontre.id}")
     *   - Multi-tenant : rencontre doit appartenir au club courant
     */
    #[Route(
        '/rencontres/{id}/joueur-rapide',
        name: 'manager_rencontre_joueur_rapide',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function ajouterJoueurRapide(Rencontre $rencontre, Request $request): JsonResponse
    {
        // Auth
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // CSRF
        $csrfToken = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('joueur_rapide_' . $rencontre->getId(), $csrfToken)) {
            return $this->json(['error' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        // Lecture body JSON
        $body = json_decode((string) $request->getContent(), true) ?? [];

        $prenom = trim((string) ($body['prenom'] ?? ''));
        $nom    = trim((string) ($body['nom'] ?? ''));

        if ($prenom === '' || $nom === '') {
            return $this->json(['error' => 'Prénom et nom sont requis.'], Response::HTTP_BAD_REQUEST);
        }

        $numero         = isset($body['numero']) ? (int) $body['numero'] : null;
        $equipeEphemere = isset($body['equipeEphemere']) && $body['equipeEphemere'] !== ''
            ? (string) $body['equipeEphemere']
            : null;
        $couleur        = isset($body['couleurMaillot']) && $body['couleurMaillot'] !== ''
            ? substr((string) $body['couleurMaillot'], 0, 20)
            : null;

        // Validation numéro maillot
        if ($numero !== null && ($numero < 0 || $numero > 99)) {
            return $this->json(['error' => 'Numéro de maillot invalide (0–99).'], Response::HTTP_BAD_REQUEST);
        }

        // Création du Joueur éphémère
        // Pas d'équipe officielle assignée — isTemporaire=true l'identifie
        $joueur = new Joueur();
        $joueur->setClub($rencontre->getClub());
        $joueur->setPrenom($prenom);
        $joueur->setNom($nom);
        $joueur->setNumeroMaillot($numero);
        $joueur->setIsTemporaire(true);
        $joueur->setEquipeEphemere($equipeEphemere);
        $joueur->setCouleurMaillot($couleur);
        $joueur->setRencontreOrigine($rencontre);

        // On lui assigne l'équipe de la rencontre seulement si c'est une joueuse "notre équipe"
        // → les stats live peuvent la trouver via rencontreOrigine ou via equipe
        if ($equipeEphemere === null) {
            $joueur->setEquipe($rencontre->getEquipe());
        }

        $this->em->persist($joueur);
        $this->em->flush();

        // Passer le token CSRF de suppression dans la réponse pour que le JS puisse
        // supprimer la joueuse dynamiquement sans reload
        $csrfSupprimer = $this->csrfTokenManager
            ->getToken('supprimer_ephemere_' . $joueur->getId())
            ->getValue();

        return $this->json([
            'id'             => $joueur->getId(),
            'nomComplet'     => $joueur->getNomComplet(),
            'prenom'         => $joueur->getPrenom(),
            'nom'            => $joueur->getNom(),
            'numero'         => $joueur->getNumeroMaillot(),
            'equipeEphemere' => $joueur->getEquipeEphemere(),
            'couleur'        => $joueur->getCouleurMaillot(),
            'isAdverse'      => $joueur->isEphemereAdverse(),
            'csrfSupprimer'  => $csrfSupprimer,
        ], Response::HTTP_CREATED);
    }

    /**
     * Suppression d'une joueuse éphémère.
     *
     * DELETE /joueurs/{id}/ephemere
     * Sécurité : CLUB_STAFF, multi-tenant, isTemporaire doit être true.
     */
    #[Route(
        '/joueurs/{id}/ephemere',
        name: 'manager_joueur_ephemere_supprimer',
        methods: ['DELETE'],
        requirements: ['id' => '\d+']
    )]
    public function supprimerJoueurEphemere(Joueur $joueur, Request $request): JsonResponse
    {
        // Multi-tenant + auth
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club || $joueur->getClub()->getId() !== $club->getId()) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        // Sécurité : ne peut supprimer QUE les éphémères (pas les vraies joueuses)
        if (!$joueur->isTemporaire()) {
            return $this->json(['error' => 'Seules les joueuses éphémères peuvent être supprimées par cette route.'], Response::HTTP_FORBIDDEN);
        }

        // CSRF
        $csrfToken = $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('supprimer_ephemere_' . $joueur->getId(), $csrfToken)) {
            return $this->json(['error' => 'Token CSRF invalide.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($joueur);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * Conversion joueuse éphémère → joueuse officielle ("Recruter").
     *
     * POST /joueurs/{id}/recruter
     *
     * Body form (multipart ou JSON) :
     *   equipeId  : int (optionnel — sinon reste sur l'équipe de la rencontre)
     *   licence   : string (optionnel)
     *
     * La conversion conserve TOUT l'historique ActionMatch / PresenceTerrain.
     * Seul isTemporaire passe à false. L'historique reste lié à ce joueur.
     *
     * Réponse : redirect vers la fiche joueuse Manager (ou JSON si X-Requested-With: XMLHttpRequest).
     */
    #[Route(
        '/joueurs/{id}/recruter',
        name: 'manager_joueur_recruter',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function recruterJoueur(Joueur $joueur, Request $request): Response
    {
        // Multi-tenant
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club || $joueur->getClub()->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        // Sécurité : doit être éphémère
        if (!$joueur->isTemporaire()) {
            $this->addFlash('warning', 'Cette joueuse est déjà officielle.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        // CSRF
        $token = $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('recruter_joueur_' . $joueur->getId(), $token)) {
            $this->addFlash('error', 'Token de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        // Équipe cible
        $equipe = null;
        $equipeId = (int) $request->request->get('equipeId', 0);
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            if ($equipe && $equipe->getClub()->getId() !== $club->getId()) {
                $equipe = null; // anti-IDOR
            }
        }

        $licence = trim((string) $request->request->get('licence', '')) ?: null;

        // Conversion — garde tout l'historique ActionMatch intact
        $joueur->recruter($equipe, $licence);

        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✓ %s recrutée ! Son historique de stats est conservé.',
            $joueur->getNomComplet()
        ));

        // Redirige vers la fiche joueur si la route existe
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }
}
