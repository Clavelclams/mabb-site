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

        $role     = (string) $request->request->get('role', '');
        $userId   = (int)    $request->request->get('user_id', 0);
        $note     = (string) $request->request->get('note', '');
        // [V2.4g] Saisie libre (service civique / externe sans compte) + infos terrain
        $nomLibre = trim((string) $request->request->get('nom_libre', ''));
        $numLic   = trim((string) $request->request->get('numero_licence', ''));
        $heureRdv = trim((string) $request->request->get('heure_rdv', ''));

        if (!isset(AffectationMatch::ROLES[$role])) {
            $this->addFlash('error', 'Rôle invalide.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $user = $userId > 0 ? $this->em->getRepository(User::class)->find($userId) : null;

        if ($user === null && $nomLibre === '') {
            $this->addFlash('error', 'Choisis un membre OU saisis un nom libre (service civique, externe…).');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        // Supprimer toute affectation ASSIGNE/CONFIRME existante sur ce rôle
        foreach ($this->affectationRepo->findByRencontre($rencontre)[$role] ?? [] as $existing) {
            if ($existing->isActif()) {
                $this->em->remove($existing);
            }
        }

        $affectation = new AffectationMatch();
        $affectation->setRencontre($rencontre);
        $affectation->setUser($user);
        $affectation->setNomLibre($user === null ? $nomLibre : null);
        $affectation->setNumeroLicence($numLic ?: null);
        $affectation->setHeureRdv($heureRdv ?: null);
        $affectation->setRole($role);
        $affectation->setStatut(AffectationMatch::STATUT_ASSIGNE);
        $affectation->setNote($note ?: null);

        $this->em->persist($affectation);
        $this->em->flush();

        $nomUser = $affectation->getPersonneNom();
        $this->addFlash('success', "✅ {$nomUser} assigné(e) au rôle « " . AffectationMatch::ROLES[$role] . " ».");

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Bénévole : se candidater sur un rôle vacant
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/staff/candidater', name: 'candidater', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function candidater(Request $request, Rencontre $rencontre, \App\Service\Otm\OtmService $otm): Response
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

        // [OTM V2] Renfort (« assisté de ») plutôt que titulaire du poste.
        $assistant = (bool) $request->request->get('assistant', 0);

        // Déjà une inscription de cette personne sur ce poste ?
        $existing = $this->affectationRepo->findCandidatureByUserAndRencontreAndRole($user, $rencontre, $role);
        if ($existing !== null) {
            $this->addFlash('warning', 'Tu as déjà une inscription en cours pour ce poste.');
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        // [OTM V2] TOUTES les règles passent par OtmService : fenêtre J-7 →
        // mercredi, poste interdit, poste titulaire déjà pris, anti-répétition
        // (max 2× le même poste dans la journée).
        $refus = $otm->motifRefus($rencontre, $user, $role, $assistant, false);
        if ($refus !== null) {
            $this->addFlash('warning', $refus);
            return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
        }

        $affectation = new AffectationMatch();
        $affectation->setRencontre($rencontre);
        $affectation->setUser($user);
        $affectation->setRole($role);
        $affectation->setEstAssistant($assistant);
        $affectation->setStatut(AffectationMatch::STATUT_CANDIDAT);

        $this->em->persist($affectation);
        $this->em->flush();

        $this->addFlash('success', $assistant
            ? '🙌 Inscription en renfort prise en compte ! Un dirigeant va valider.'
            : '📝 Inscription prise en compte ! Un dirigeant va valider.');

        return $this->redirectToRoute('manager_rencontre_staff_show', ['id' => $rencontre->getId()]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // [OTM V2 12/07] KANBAN DES POSTES — on glisse une carte sur une colonne
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le tableau des postes d'une rencontre : colonnes = postes, cartes = gens.
     *
     * - un DIRIGEANT/STAFF déplace tout le monde, quand il veut ;
     * - un membre simple ne peut glisser QUE sa propre carte, et seulement
     *   pendant la fenêtre (J-7 → mercredi 23h59).
     */
    #[Route('/{id}/postes', name: 'postes', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function postes(Rencontre $rencontre, \App\Service\Otm\OtmService $otm): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);
        if ($rencontre->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        // Colonnes : un poste = une colonne. Plus la colonne « Disponibles ».
        $parRole = $this->affectationRepo->findByRencontre($rencontre);
        $colonnes = [];
        $placesIds = [];
        foreach (AffectationMatch::ROLES as $code => $libelle) {
            $colonnes[$code] = ['libelle' => $libelle, 'cartes' => []];
            foreach ($parRole[$code] ?? [] as $a) {
                /** @var AffectationMatch $a */
                if (!$a->isCouvert() || $a->getUser() === null) {
                    continue;
                }
                $colonnes[$code]['cartes'][] = $a;
                $placesIds[] = $a->getUser()->getId();
            }
        }

        // Le vivier : tous les membres actifs du club, moins ceux déjà placés.
        $membres = $this->em->getRepository(\App\Entity\Core\UserClubRole::class)
            ->createQueryBuilder('ucr')
            ->select('DISTINCT u')->from(\App\Entity\Core\User::class, 'u')
            ->join(\App\Entity\Core\UserClubRole::class, 'ucr2', 'WITH', 'ucr2.user = u')
            ->where('ucr2.club = :club')->setParameter('club', $club)
            ->andWhere('ucr2.status = :actif')->setParameter('actif', \App\Entity\Core\UserClubRole::STATUS_ACTIVE)
            ->andWhere('u.isActive = true')
            ->orderBy('u.nom', 'ASC')
            ->getQuery()->getResult();

        $disponibles = array_values(array_filter(
            $membres,
            static fn ($u) => !in_array($u->getId(), $placesIds, true)
        ));

        return $this->render('manager/rencontre/postes.html.twig', [
            'club'        => $club,
            'rencontre'   => $rencontre,
            'colonnes'    => $colonnes,
            'disponibles' => $disponibles,
            'fenetre'     => $otm->fenetre($rencontre),
            'est_admin'   => $this->isGranted(ClubVoter::CLUB_STAFF, $club),
            'moi'         => $this->getUser()?->getId(),
        ]);
    }

    /**
     * Le drop : on pose une personne sur un poste (ou on la remet au vivier).
     * Toutes les règles passent par OtmService — aucune exception.
     */
    #[Route('/{id}/postes/deplacer', name: 'postes_deplacer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function postesDeplacer(
        Request $request,
        Rencontre $rencontre,
        \App\Service\Otm\OtmService $otm,
    ): \Symfony\Component\HttpFoundation\JsonResponse {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null || $rencontre->getClub()?->getId() !== $club->getId()) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => 'Club invalide.'], 403);
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        if (!$this->isCsrfTokenValid('otm_postes_' . $rencontre->getId(), (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => 'Jeton invalide — recharge la page.'], 403);
        }

        $data   = json_decode($request->getContent(), true);
        $userId = is_array($data) ? (int) ($data['user_id'] ?? 0) : 0;
        $role   = is_array($data) ? trim((string) ($data['role'] ?? '')) : '';

        $cible = $this->em->getRepository(\App\Entity\Core\User::class)->find($userId);
        if ($cible === null) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => 'Personne introuvable.'], 404);
        }

        $estAdmin = $this->isGranted(ClubVoter::CLUB_STAFF, $club);
        $moi      = $this->getUser();

        // Un membre simple ne déplace QUE sa propre carte.
        if (!$estAdmin && $cible->getId() !== $moi?->getId()) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(
                ['success' => false, 'error' => 'Tu ne peux placer que toi-même.'], 403);
        }

        // Retirer les affectations actives de cette personne sur cette rencontre :
        // une personne ne tient qu'UN poste par match.
        foreach ($this->affectationRepo->findByRencontre($rencontre) as $liste) {
            foreach ($liste as $a) {
                /** @var AffectationMatch $a */
                if ($a->isCouvert() && $a->getUser()?->getId() === $cible->getId()) {
                    $this->em->remove($a);
                }
            }
        }

        // Colonne « Disponibles » → on retire, point.
        if ($role === '') {
            $this->em->flush();
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => true, 'role' => null]);
        }

        $this->em->flush(); // la règle « poste déjà pris » doit voir le retrait

        // Titulaire si le poste est libre, sinon renfort (« assisté de »).
        $titulaire = $this->affectationRepo->findActiveByRencontreAndRole($rencontre, $role);
        $assistant = $titulaire !== null && $titulaire->isTitulaire();

        $refus = $otm->motifRefus($rencontre, $cible, $role, $assistant, $estAdmin);
        if ($refus !== null) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => false, 'error' => $refus], 400);
        }

        $a = (new AffectationMatch())
            ->setRencontre($rencontre)
            ->setUser($cible)
            ->setRole($role)
            ->setEstAssistant($assistant)
            ->setStatut(AffectationMatch::STATUT_ASSIGNE);

        $this->em->persist($a);
        $this->em->flush();

        return new \Symfony\Component\HttpFoundation\JsonResponse([
            'success'   => true,
            'role'      => $role,
            'assistant' => $assistant,
        ]);
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

    // ────────────────────────────────────────────────────────────────────────
    // [V2.4g 09/07/2026] Organisation du WEEK-END — vue globale
    // Remplace l'Excel « Organisation match » : tous les matchs du week-end
    // groupés par jour puis par salle, avec l'état des postes (pourvu/vacant),
    // heures de RDV et n° de licence. Imprimable pour l'affichage en salle.
    // ────────────────────────────────────────────────────────────────────────

    #[Route('/organisation-weekend', name: 'organisation_weekend', methods: ['GET'])]
    public function organisationWeekend(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        // [V2.4m] Dashboard OTM (Officiels de Table de Marque) — accès staff
        // ÉLARGI : dirigeants, coachs, staff, trésorier, EMPLOYÉS/services
        // civiques et secrétaire. Ce sont eux qui tiennent la table de marque.
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF_ELARGI, $club);

        // Samedi de référence : ?date=YYYY-MM-DD (n'importe quel jour de la
        // semaine visée) — défaut : le week-end courant ou à venir.
        $param = (string) $request->query->get('date', '');
        try {
            $ref = $param !== '' ? new \DateTimeImmutable($param) : new \DateTimeImmutable('today');
        } catch (\Exception) {
            $ref = new \DateTimeImmutable('today');
        }
        // 'saturday this week' sur un dimanche renverrait le samedi passé →
        // on se cale sur le samedi de la semaine ISO du jour de référence.
        $samedi = $ref->modify('monday this week')->modify('+5 days')->setTime(0, 0);
        $lundi  = $samedi->modify('+2 days');

        $rencontres = $this->rencontreRepo->createQueryBuilder('r')
            ->andWhere('r.club = :club')->setParameter('club', $club)
            ->andWhere('r.date >= :debut')->setParameter('debut', $samedi)
            ->andWhere('r.date < :fin')->setParameter('fin', $lundi)
            ->orderBy('r.date', 'ASC')
            ->getQuery()->getResult();

        // Groupement jour → salle → rencontres, avec les affectations par rôle
        $parJourEtSalle = [];
        foreach ($rencontres as $r) {
            $jour  = $r->getDate()?->format('Y-m-d') ?? '???';
            $salle = $r->isDomicile() ? ($r->getLieu() ?: 'Salle non précisée') : 'Extérieur';
            $parJourEtSalle[$jour][$salle][] = [
                'rencontre'    => $r,
                'affectations' => $this->affectationRepo->findByRencontre($r),
            ];
        }
        ksort($parJourEtSalle);

        return $this->render('manager/rencontre/organisation_weekend.html.twig', [
            'club'             => $club,
            'samedi'           => $samedi,
            'dimanche'         => $samedi->modify('+1 day'),
            'weekend_prec'     => $samedi->modify('-7 days')->format('Y-m-d'),
            'weekend_suiv'     => $samedi->modify('+7 days')->format('Y-m-d'),
            'par_jour_et_salle' => $parJourEtSalle,
            'roles'            => AffectationMatch::ROLES,
            'nb_rencontres'    => count($rencontres),
        ]);
    }

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
