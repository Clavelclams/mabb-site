<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\CoachEquipe;
use App\Entity\Sport\Equipe;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerStaffController — Page Staff : liste des Coachs, Dirigeants,
 * Trésoriers et Staff du club, groupés par rôle.
 *
 * Inclut désormais la gestion des affectations Coach ↔ Équipe (CoachEquipe).
 *
 * Accès :
 *   - Liste + fiche : CLUB_MEMBER (tout membre voit l'organigramme)
 *   - Affecter/retirer coach ↔ équipe : CLUB_STAFF (dirigeant/staff)
 */
class ManagerStaffController extends AbstractController
{
    /** Calcule la saison courante (identique à ManagerCoachDashboardController). */
    private static function saisonCourante(): string
    {
        $now   = new \DateTimeImmutable();
        $annee = (int) $now->format('Y');
        $mois  = (int) $now->format('n');
        return $mois >= 9
            ? $annee . '-' . ($annee + 1)
            : ($annee - 1) . '-' . $annee;
    }

    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly JoueurRepository $joueurRepository,
    ) {}

    /**
     * Liste du staff du club, groupé par rôle.
     * GET manager.mabb.fr/staff
     */
    #[Route('/staff', name: 'manager_staff_index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            $this->addFlash('error', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        $rolesStaff = [
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
            UserClubRole::ROLE_TRESORIER,
        ];

        $ucrs = $this->em->getRepository(UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->leftJoin('ucr.user', 'u')
            ->addSelect('u')
            ->where('ucr.club = :club')
            ->andWhere('ucr.status = :active')
            ->andWhere('ucr.role IN (:roles)')
            ->setParameter('club', $club)
            ->setParameter('active', UserClubRole::STATUS_ACTIVE)
            ->setParameter('roles', $rolesStaff)
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        $groupes = [
            UserClubRole::ROLE_DIRIGEANT => [],
            UserClubRole::ROLE_COACH     => [],
            UserClubRole::ROLE_STAFF     => [],
            UserClubRole::ROLE_TRESORIER => [],
        ];
        foreach ($ucrs as $ucr) {
            $role = $ucr->getRole();
            if (isset($groupes[$role])) {
                $groupes[$role][] = $ucr;
            }
        }

        $libelles = [
            UserClubRole::ROLE_DIRIGEANT => 'Dirigeants',
            UserClubRole::ROLE_COACH     => 'Coachs',
            UserClubRole::ROLE_STAFF     => 'Staff',
            UserClubRole::ROLE_TRESORIER => 'Trésorerie',
        ];

        $icones = [
            UserClubRole::ROLE_DIRIGEANT => 'bi-shield-fill-check',
            UserClubRole::ROLE_COACH     => 'bi-clipboard-pulse',
            UserClubRole::ROLE_STAFF     => 'bi-briefcase',
            UserClubRole::ROLE_TRESORIER => 'bi-cash-coin',
        ];

        $couleurs = [
            UserClubRole::ROLE_DIRIGEANT => '#dc2626',
            UserClubRole::ROLE_COACH     => '#3b82f6',
            UserClubRole::ROLE_STAFF     => '#0891b2',
            UserClubRole::ROLE_TRESORIER => '#16a34a',
        ];

        return $this->render('manager/staff/index.html.twig', [
            'club'     => $club,
            'groupes'  => $groupes,
            'libelles' => $libelles,
            'icones'   => $icones,
            'couleurs' => $couleurs,
            'total'    => count($ucrs),
        ]);
    }

    /**
     * Fiche détaillée d'un membre du staff.
     * Affiche ses rôles + ses équipes coachées (CoachEquipe) + formulaire d'affectation.
     * GET manager.mabb.fr/staff/{userId}
     */
    #[Route('/staff/{userId}', name: 'manager_staff_show', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function show(int $userId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        $user = $this->em->getRepository(User::class)->find($userId);
        if ($user === null) {
            throw new NotFoundHttpException('Utilisateur introuvable.');
        }

        $ucrs = $this->em->getRepository(UserClubRole::class)->findBy([
            'user'   => $user,
            'club'   => $club,
            'status' => UserClubRole::STATUS_ACTIVE,
        ]);

        if ($ucrs === []) {
            throw new NotFoundHttpException('Cet utilisateur n\'a aucun rôle actif dans ce club.');
        }

        $roles = array_map(fn($u) => $u->getRole(), $ucrs);

        $depuis = null;
        foreach ($ucrs as $u) {
            $d = $u->getCreatedAt();
            if ($d !== null && ($depuis === null || $d < $depuis)) {
                $depuis = $d;
            }
        }

        // Joueur lié à cet user dans ce club
        $joueurLie = $this->em->getRepository(\App\Entity\Sport\Joueur::class)->findOneBy([
            'user' => $user,
            'club' => $club,
        ]);

        // ── CoachEquipe : toutes les affectations de ce coach (toutes saisons)
        // On ne filtre PAS par saison ici pour éviter tout mismatch de valeur stockée.
        // Le template les regroupe par saison s'il y en a plusieurs.
        $coachEquipes = $this->coachEquipeRepository->findByCoach($user, null);

        // Équipes disponibles du club : toutes les équipes actives, sans filtre saison,
        // pour que l'admin puisse affecter même si la saison en DB diffère du calcul courant.
        $equipesDisponibles = $this->em->createQueryBuilder()
            ->select('e')
            ->from(Equipe::class, 'e')
            ->where('e.club = :club')
            ->andWhere('e.isActive = true')
            ->orderBy('e.saison', 'DESC')
            ->addOrderBy('e.nom', 'ASC')
            ->setParameter('club', $club)
            ->getQuery()
            ->getResult();

        // IDs des équipes déjà assignées (pour griser dans le select)
        $equipesDejaCoachs = array_map(
            fn(CoachEquipe $ce) => $ce->getEquipe()?->getId(),
            $coachEquipes
        );

        return $this->render('manager/staff/show.html.twig', [
            'club'                  => $club,
            'user_membre'           => $user,
            'roles'                 => $roles,
            'ucrs'                  => $ucrs,
            'depuis'                => $depuis,
            'joueur_lie'            => $joueurLie,
            'coach_equipes'         => $coachEquipes,
            'equipes_disponibles'   => $equipesDisponibles,
            'equipes_deja_coachs'   => $equipesDejaCoachs,
            'saison_courante'       => self::saisonCourante(),
            'coach_roles'           => CoachEquipe::ROLES,
        ]);
    }

    /**
     * Affecte un coach à une équipe (crée un CoachEquipe).
     * POST manager.mabb.fr/staff/{userId}/affecter-equipe
     * Accessible uniquement CLUB_STAFF.
     */
    #[Route('/staff/{userId}/affecter-equipe', name: 'manager_staff_affecter_equipe', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function affecterEquipe(Request $request, int $userId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if (!$this->isCsrfTokenValid('affecter_equipe_' . $userId, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_staff_show', ['userId' => $userId]);
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if ($user === null) {
            throw new NotFoundHttpException('Utilisateur introuvable.');
        }

        $equipeId = (int) $request->request->get('equipe_id', 0);
        $equipe = $this->em->getRepository(Equipe::class)->find($equipeId);
        if ($equipe === null || $equipe->getClub()?->getId() !== $club->getId()) {
            $this->addFlash('error', 'Équipe introuvable ou n\'appartient pas au club.');
            return $this->redirectToRoute('manager_staff_show', ['userId' => $userId]);
        }

        $roleCoach = (string) $request->request->get('role_coach', CoachEquipe::ROLE_PRINCIPAL);
        if (!in_array($roleCoach, CoachEquipe::ROLES, true)) {
            $roleCoach = CoachEquipe::ROLE_PRINCIPAL;
        }

        $saison = self::saisonCourante();

        // Vérifier doublon
        $existing = $this->em->createQueryBuilder()
            ->select('ce')
            ->from(CoachEquipe::class, 'ce')
            ->where('ce.user = :u')
            ->andWhere('ce.equipe = :e')
            ->andWhere('ce.saison = :s')
            ->setParameter('u', $user)
            ->setParameter('e', $equipe)
            ->setParameter('s', $saison)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existing !== null) {
            $this->addFlash('warning', sprintf(
                '%s %s est déjà assigné(e) à l\'équipe "%s" pour la saison %s.',
                $user->getPrenom(), $user->getNom(), $equipe->getNom(), $saison
            ));
            return $this->redirectToRoute('manager_staff_show', ['userId' => $userId]);
        }

        $ce = new CoachEquipe();
        $ce->setUser($user);
        $ce->setEquipe($equipe);
        $ce->setRoleCoach($roleCoach);
        $ce->setSaison($saison);

        $this->em->persist($ce);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✅ %s %s affecté(e) à l\'équipe "%s" (%s) — saison %s.',
            $user->getPrenom(), $user->getNom(), $equipe->getNom(), $roleCoach, $saison
        ));

        return $this->redirectToRoute('manager_staff_show', ['userId' => $userId]);
    }

    /**
     * Retire l'affectation d'un coach d'une équipe.
     * POST manager.mabb.fr/staff/coach-equipe/{coachEquipeId}/retirer
     * Accessible uniquement CLUB_STAFF.
     */
    #[Route('/staff/coach-equipe/{coachEquipeId}/retirer', name: 'manager_staff_retirer_equipe', methods: ['POST'], requirements: ['coachEquipeId' => '\d+'])]
    public function retirerEquipe(Request $request, int $coachEquipeId): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $ce = $this->em->getRepository(CoachEquipe::class)->find($coachEquipeId);
        if ($ce === null) {
            throw new NotFoundHttpException('Affectation introuvable.');
        }

        // Vérification multi-tenant : le club de l'équipe = club actif
        if ($ce->getEquipe()?->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException('Cette affectation n\'appartient pas au club actif.');
        }

        $userId   = $ce->getUser()?->getId();
        $nomUser  = $ce->getUser()?->getPrenom() . ' ' . $ce->getUser()?->getNom();
        $nomEquipe = $ce->getEquipe()?->getNom() ?? '?';

        if (!$this->isCsrfTokenValid('retirer_equipe_' . $coachEquipeId, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_staff_show', ['userId' => $userId ?? 0]);
        }

        $this->em->remove($ce);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✅ %s retiré(e) de l\'équipe "%s".',
            $nomUser, $nomEquipe
        ));

        return $this->redirectToRoute('manager_staff_show', ['userId' => $userId ?? 0]);
    }

    /**
     * Vue admin : Users du club qui ont créé un compte PIRB mais n'ont pas encore
     * de fiche Joueur liée. L'admin peut voir une suggestion auto-détectée par email
     * et lier directement depuis cette page.
     *
     * GET manager.mabb.fr/staff/comptes-en-attente
     */
    #[Route('/staff/comptes-en-attente', name: 'manager_staff_comptes_en_attente', methods: ['GET'])]
    public function comptesEnAttente(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $entrees = $this->joueurRepository->findUsersWithoutJoueur($club);

        return $this->render('manager/staff/comptes_en_attente.html.twig', [
            'club'    => $club,
            'entrees' => $entrees, // array<{user: User, suggestion: Joueur|null}>
        ]);
    }
}
