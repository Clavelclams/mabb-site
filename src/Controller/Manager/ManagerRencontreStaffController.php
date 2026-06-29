<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\AffectationMatchRepository;
use App\Repository\Sport\RencontreRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion du staff (affectations) par rencontre.
 *
 * Routes manager.mabb.fr :
 *   GET  /rencontres/{id}/staff               → vue staff d'une rencontre
 *   POST /rencontres/{id}/staff/assigner       → admin assigne un user à un rôle
 *   POST /rencontres/{id}/staff/candidater     → user bénévole s'inscrit sur un rôle vacant
 *   POST /affectations/{aid}/valider           → admin valide une candidature
 *   POST /affectations/{aid}/rejeter           → admin rejette une candidature
 *   POST /affectations/{aid}/absent            → admin marque absent
 *   POST /affectations/{aid}/supprimer         → admin supprime une affectation
 *
 *   GET  /mes-missions                         → tableau de bord missions du user connecté
 */
#[Route('/rencontres', name: 'manager_rencontre_staff_')]
class ManagerRencontreStaffController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver            $tenantResolver,
        private readonly EntityManagerInterface    $em,
        private readonly AffectationMatchRepository $affectationRepo,
        private readonly RencontreRepository        $rencontreRepo,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    // Vue staff d'une rencontre
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/staff', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Rencontre $rencontre): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Multi-tenant check
        if ($rencontre->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        // Map rôle → affectations (actives + candidatures)
        $affectationsByRole = $this->affectationRepo->findByRencontre($rencontre);

        // Rôles vacants = aucun ASSIGNE ni CONFIRME (ABSENT compte comme vacant : besoin d'un remplaçant)
        $rolesVacants = [];
        foreach (AffectationMatch::ROLES as $roleCode => $roleLabel) {
            $hasCouvert = false;
            foreach ($affectationsByRole[$roleCode] ?? [] as $a) {
                if ($a->isCouvert()) { $hasCouvert = true; break; }
            }
            if (!$hasCouvert) {
                $rolesVacants[] = $roleCode;
            }
        }

        // Candidature déjà posée par ce user sur cette rencontre (pour masquer "Je m'inscris")
        $mesRolesCandidates = [];
        foreach ($affectationsByRole as $roleCode => $affectations) {
            foreach ($affectations as $a) {
                if ($a->getUser()?->getId() === $user->getId()) {
                    $mesRolesCandidates[] = $roleCode;
                }
            }
        }

        // Liste users du club pour le formulaire d'assignation admin
        $usersClub = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->leftJoin('u.userClubRoles', 'ucr')
            ->where('ucr.club = :club')
            ->andWhere('ucr.status = :active')
            ->setParameter('club', $club)
            ->setParameter('active', 'ACTIVE')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('manager/rencontre/staff.html.twig', [
            'rencontre'            => $rencontre,
            'club'                 => $club,
            'affectations_by_role' => $affectationsByRole,
            'roles'                => AffectationMatch::ROLES,
            'roles_vacants'        => $rolesVacants,
            'mes_roles_candidates' => $mesRolesCandidates,
            'users_club'           => $usersClub,
            'is_admin'             => $this->isGranted(ClubVoter::CLUB_STAFF, $club),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Admin : assigne un user à un rôle
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/staff/assigner', name: 'assigner', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assigner(Request $request, Rencontre $rencontre): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if ($rencontre->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('staff_assigner_' . $rencontre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $role   = (string) $request->request->get('role', '');
        $userId = (int)    $request->request->get('user_id', 0);
        $note   = (string) $request->request->get('note', '');

        if (!isset(AffectationMatch::ROLES[$role])) {
            $this->addFlash('error', 'Rôle invalide.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $user = $userId > 0 ? $this->em->getRepository(User::class)->find($userId) : null;

        // Supprimer toute affectation ASSIGNE/CONFIRME existante sur ce rôle
        foreach ($this->affectationRepo->findByRencontre($rencontre)[$role] ?? [] as $existing) {
            if ($existing->isActif()) {
                $this->em->remove($existing);
            }
        }

        $affectation = new AffectationMatch();
        $affectation->setRencontre($rencontre);
        $affectation->setUser($user);
        $affectation->setRole($role);
        $affectation->setStatut(AffectationMatch::STATUT_ASSIGNE);
        $affectation->setNote($note ?: null);

        $this->em->persist($affectation);
        $this->em->flush();

        $nomUser = $user ? $user->getPrenom() . ' ' . $user->getNom() : 'Poste réservé (sans user)';
        $this->addFlash('success', "✅ {$nomUser} assigné(e) au rôle « " . AffectationMatch::ROLES[$role] . " ».");

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Bénévole : se candidater sur un rôle vacant
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/staff/candidater', name: 'candidater', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function candidater(Request $request, Rencontre $rencontre): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        if ($rencontre->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('staff_candidater_' . $rencontre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $role = (string) $request->request->get('role', '');
        if (!isset(AffectationMatch::ROLES[$role])) {
            $this->addFlash('error', 'Rôle invalide.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Vérifier qu'il n'y a pas déjà une candidature de ce user sur ce rôle
        $existing = $this->affectationRepo->findCandidatureByUserAndRencontreAndRole($user, $rencontre, $role);
        if ($existing !== null) {
            $this->addFlash('warning', 'Tu as déjà une inscription en cours pour ce rôle.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        // Vérifier que le rôle est bien vacant (pas d'actif)
        $actif = $this->affectationRepo->findActiveByRencontreAndRole($rencontre, $role);
        if ($actif !== null) {
            $this->addFlash('warning', 'Ce rôle est déjà pourvu.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $affectation = new AffectationMatch();
        $affectation->setRencontre($rencontre);
        $affectation->setUser($user);
        $affectation->setRole($role);
        $affectation->setStatut(AffectationMatch::STATUT_CANDIDAT);

        $this->em->persist($affectation);
        $this->em->flush();

        $this->addFlash('success', '📝 Inscription prise en compte ! L\'admin va valider ta candidature.');

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Admin : valider une candidature bénévole
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/affectations/{aid}/valider', name: 'valider', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function valider(Request $request, int $aid): Response
    {
        $affectation = $this->em->getRepository(AffectationMatch::class)->find($aid);
        if ($affectation === null) {
            throw $this->createNotFoundException();
        }

        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if (!$this->isCsrfTokenValid('staff_valider_' . $aid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
        } else {
            // Supprimer les autres candidatures pour ce même rôle/rencontre
            $rencontre = $affectation->getRencontre();
            $role      = $affectation->getRole();
            foreach ($this->affectationRepo->findByRencontre($rencontre)[$role] ?? [] as $a) {
                if ($a->getId() !== $affectation->getId() && $a->isCandidature()) {
                    $this->em->remove($a);
                }
            }
            $affectation->setStatut(AffectationMatch::STATUT_CONFIRME);
            $this->em->flush();
            $this->addFlash('success', '✅ Candidature confirmée.');
        }

        return $this->redirectToRoute('manager_rencontre_staff_show', [
            'id' => $affectation->getRencontre()?->getId(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Admin : rejeter une candidature
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/affectations/{aid}/rejeter', name: 'rejeter', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function rejeter(Request $request, int $aid): Response
    {
        $affectation = $this->em->getRepository(AffectationMatch::class)->find($aid);
        if ($affectation === null) {
            throw $this->createNotFoundException();
        }

        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $rencontreId = $affectation->getRencontre()?->getId();

        if (!$this->isCsrfTokenValid('staff_rejeter_' . $aid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
        } else {
            $this->em->remove($affectation);
            $this->em->flush();
            $this->addFlash('success', 'Candidature rejetée.');
        }

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontreId]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Admin : marquer absent
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/affectations/{aid}/absent', name: 'absent', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function absent(Request $request, int $aid): Response
    {
        $affectation = $this->em->getRepository(AffectationMatch::class)->find($aid);
        if ($affectation === null) {
            throw $this->createNotFoundException();
        }

        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if (!$this->isCsrfTokenValid('staff_absent_' . $aid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
        } else {
            $note = (string) $request->request->get('note', '');
            $affectation->setStatut(AffectationMatch::STATUT_ABSENT);
            $affectation->setNote($note ?: null);
            $this->em->flush();
            $this->addFlash('warning', '⚠️ Absence signalée. Pense à trouver un remplaçant.');
        }

        return $this->redirectToRoute('manager_rencontre_staff_show', [
            'id' => $affectation->getRencontre()?->getId(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Admin : supprimer une affectation
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/affectations/{aid}/supprimer', name: 'supprimer', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function supprimer(Request $request, int $aid): Response
    {
        $affectation = $this->em->getRepository(AffectationMatch::class)->find($aid);
        if ($affectation === null) {
            throw $this->createNotFoundException();
        }

        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $rencontreId = $affectation->getRencontre()?->getId();

        if (!$this->isCsrfTokenValid('staff_supprimer_' . $aid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
        } else {
            $this->em->remove($affectation);
            $this->em->flush();
            $this->addFlash('success', 'Affectation supprimée.');
        }

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontreId]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Mes missions (vue personnelle pour tout membre connecté)
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/missions', name: 'mes_missions', methods: ['GET'])]
    public function mesMissions(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        /** @var User $user */
        $user = $this->getUser();

        $missionsAVenir     = $this->affectationRepo->findMissionsAVenir($user);
        $candidaturesEnCours = $this->affectationRepo->findCandidaturesEnAttente($user);

        // Rencontres à venir avec des rôles vacants (pour proposer les inscriptions)
        $now = new \DateTimeImmutable('today');
        $rencontresAVenir = $this->rencontreRepo->createQueryBuilder('r')
            ->where('r.club = :club')
            ->andWhere('r.date >= :now')
            ->setParameter('club', $club)
            ->setParameter('now', $now)
            ->orderBy('r.date', 'ASC')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult();

        // Pour chaque rencontre, trouver les rôles vacants
        $rencontresAvecVacants = [];
        foreach ($rencontresAVenir as $rencontre) {
            $affMap = $this->affectationRepo->findByRencontre($rencontre);
            $vacants = [];
            foreach (AffectationMatch::ROLES as $roleCode => $roleLabel) {
                $hasCouvert = false;
                $maCandidature = false;
                foreach ($affMap[$roleCode] ?? [] as $a) {
                    if ($a->isCouvert()) { $hasCouvert = true; }
                    if ($a->getUser()?->getId() === $user->getId()) { $maCandidature = true; }
                }
                if (!$hasCouvert && !$maCandidature) {
                    $vacants[$roleCode] = $roleLabel;
                }
            }
            if (!empty($vacants)) {
                $rencontresAvecVacants[] = [
                    'rencontre' => $rencontre,
                    'vacants'   => $vacants,
                ];
            }
        }

        return $this->render('manager/rencontre/mes_missions.html.twig', [
            'missions_a_venir'       => $missionsAVenir,
            'candidatures_en_cours'  => $candidaturesEnCours,
            'rencontres_avec_vacants' => $rencontresAvecVacants,
            'club'                    => $club,
        ]);
    }
}
