<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ProfilController — espace personnel de l'utilisateur connecté.
 *
 * Cette page affiche les infos perso, les rôles dans le club actif,
 * la fiche joueuse attachée (si existante) et donne les accès aux actions
 * personnelles (changer mdp, télécharger données RGPD, etc.).
 *
 * Préparation du futur switch de mode (Uber-style) : cette page est le
 * "home" du mode "Mon profil" parmi les modes possibles (Joueur, Coach,
 * Dirigeant, Bénévole) qui s'activeront selon les rôles cumulés.
 */
class ProfilController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly JoueurRepository $joueurRepository,
    ) {}

    #[Route('/profil', name: 'manager_profil', methods: ['GET'])]
    public function show(): Response
    {
        // ====================================================================
        // Récupération de l'utilisateur connecté
        // $this->getUser() retourne le User authentifié, jamais null ici
        // car le firewall manager force l'auth sur cette route.
        // ====================================================================
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Garde-fou : si l'user n'est pas du bon type (cas pathologique),
            // on redirige vers login plutôt que de planter avec un null deref.
            return $this->redirectToRoute('manager_login');
        }

        $club = $this->tenantResolver->getCurrentClub();

        // ====================================================================
        // Rôles de l'utilisateur DANS LE CLUB actif (UserClubRole)
        // Distinction importante :
        //   - User->roles (Symfony) = ['ROLE_USER', 'ROLE_SUPER_ADMIN']
        //   - UserClubRole         = ['COACH', 'DIRIGEANT'] dans tel club
        // On filtre car un user peut avoir des rôles dans plusieurs clubs.
        // ====================================================================
        $rolesDansClub = [];
        if ($club) {
            foreach ($user->getUserClubRoles() as $ucr) {
                if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isActive()) {
                    $rolesDansClub[] = $ucr;
                }
            }
        }

        // ====================================================================
        // Fiche Joueur attachée au compte (si l'user est une joueuse)
        // Une joueuse peut avoir un User attaché (cas majeur) ou pas (mineure).
        // On cherche dans le club actif uniquement.
        // ====================================================================
        $ficheJoueur = null;
        if ($club) {
            $ficheJoueur = $this->joueurRepository->findOneBy([
                'user' => $user,
                'club' => $club,
            ]);
        }

        return $this->render('manager/profil/show.html.twig', [
            'user'           => $user,
            'club'           => $club,
            'roles_in_club'  => $rolesDansClub,
            'fiche_joueur'   => $ficheJoueur,
        ]);
    }
}
