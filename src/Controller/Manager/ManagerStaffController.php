<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerStaffController — Page Staff : liste des Coachs, Dirigeants,
 * Trésoriers et Staff du club, groupés par rôle (style page Équipe).
 *
 * Accessible CLUB_MEMBER (tout membre voit l'organigramme du club).
 * Les actions de modification de rôles restent dans /utilisateurs (DIRIGEANT).
 */
class ManagerStaffController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
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

        // Rôles "staff/encadrant" qui apparaissent sur cette page.
        // JOUEUR et PARENT ne sont PAS du staff → exclus.
        // BENEVOLE et EMPLOYE non plus → page Joueuses/Bénévoles séparée (futur).
        $rolesStaff = [
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
            UserClubRole::ROLE_TRESORIER,
        ];

        // UserClubRole actifs du club avec rôle staff/encadrant
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

        // Groupe par rôle (un user peut avoir plusieurs rôles, il apparaît
        // dans chaque section où il a un rôle)
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

        // Libellés humains pour les sections (cohérent avec UserClubRole)
        $libelles = [
            UserClubRole::ROLE_DIRIGEANT => 'Dirigeants',
            UserClubRole::ROLE_COACH     => 'Coachs',
            UserClubRole::ROLE_STAFF     => 'Staff',
            UserClubRole::ROLE_TRESORIER => 'Trésorerie',
        ];

        // Icônes Bootstrap par rôle (visuel)
        $icones = [
            UserClubRole::ROLE_DIRIGEANT => 'bi-shield-fill-check',
            UserClubRole::ROLE_COACH     => 'bi-clipboard-pulse',
            UserClubRole::ROLE_STAFF     => 'bi-briefcase',
            UserClubRole::ROLE_TRESORIER => 'bi-cash-coin',
        ];

        // Couleurs pour les badges
        $couleurs = [
            UserClubRole::ROLE_DIRIGEANT => '#dc2626', // rouge — autorité
            UserClubRole::ROLE_COACH     => '#3b82f6', // bleu — sport
            UserClubRole::ROLE_STAFF     => '#0891b2', // cyan — admin
            UserClubRole::ROLE_TRESORIER => '#16a34a', // vert — argent
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

        // Tous les UserClubRole actifs de cet user dans CE club
        $ucrs = $this->em->getRepository(UserClubRole::class)->findBy([
            'user'   => $user,
            'club'   => $club,
            'status' => UserClubRole::STATUS_ACTIVE,
        ]);

        if ($ucrs === []) {
            throw new NotFoundHttpException('Cet utilisateur n\'a aucun rôle actif dans ce club.');
        }

        // Récupère les rôles distincts pour affichage
        $roles = array_map(fn($u) => $u->getRole(), $ucrs);

        // Date depuis quand il est dans le staff (le plus ancien UCR)
        $depuis = null;
        foreach ($ucrs as $u) {
            $d = $u->getCreatedAt();
            if ($d !== null && ($depuis === null || $d < $depuis)) {
                $depuis = $d;
            }
        }

        // Joueur lié à cet user dans ce club (si existe)
        $joueurLie = $this->em->getRepository(\App\Entity\Sport\Joueur::class)->findOneBy([
            'user' => $user,
            'club' => $club,
        ]);

        // Note : pour V2 #106, on récupèrera ici les Équipes que ce coach
        // entraîne (via une table de jointure coach_equipe), puis les
        // séances/rencontres de ces équipes. Pour l'instant, info de base.

        return $this->render('manager/staff/show.html.twig', [
            'club'        => $club,
            'user_membre' => $user,
            'roles'       => $roles,
            'ucrs'        => $ucrs,
            'depuis'      => $depuis,
            'joueur_lie'  => $joueurLie,
        ]);
    }
}
