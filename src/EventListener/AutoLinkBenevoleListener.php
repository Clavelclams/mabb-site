<?php

namespace App\EventListener;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

/**
 * AutoLinkBenevoleListener — attache automatiquement un User à MABB
 * lors de sa première connexion sur manager.mabb.fr (ou pirb.mabb.fr).
 *
 * Cas d'usage :
 *   - Un membre du club s'inscrit d'abord sur mabb.fr (vitrine publique)
 *   - Plus tard il découvre manager.mabb.fr et essaie de se connecter
 *   - Sans ce listener, son login passe le firewall mais le ClubVoter
 *     le bloque partout car il n'a aucun UserClubRole
 *
 * Solution V1 (mono-club MABB) : à la 1ère connexion manager/pirb,
 * si aucun UserClubRole, on en crée un automatiquement MABB/BENEVOLE.
 * → Accès lecture immédiat. Les actions sensibles (créer équipe, modifier
 *   joueurs…) restent bloquées par le ClubVoter qui exige COACH/STAFF/ADMIN.
 *
 * V2 (multi-clubs) : ce listener sera remplacé par une page de choix
 * "Rejoindre un club" qui s'affichera au lieu de l'auto-link.
 *
 * Le rôle BENEVOLE est volontairement non-privilégié : il permet de voir
 * mais pas de modifier. Un dirigeant pourra ensuite promouvoir le user
 * vers COACH/STAFF via une UI admin (à venir).
 */
#[AsEventListener(event: InteractiveLoginEvent::class)]
class AutoLinkBenevoleListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
    ) {}

    public function __invoke(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if (!$user instanceof User) {
            return;
        }

        // On agit uniquement sur les sous-domaines manager et pirb,
        // pas sur la vitrine (où l'absence de UserClubRole est normale).
        $request = $this->requestStack->getCurrentRequest();
        $host = $request?->getHost() ?? '';
        if (!str_starts_with($host, 'manager.') && !str_starts_with($host, 'pirb.')) {
            return;
        }

        // Si l'user a déjà au moins un UserClubRole, on ne fait rien.
        // (Il a déjà rejoint un club, l'auto-link n'a pas lieu d'être.)
        if (count($user->getUserClubRoles()) > 0) {
            return;
        }

        // V1 : on attache à l'unique club actif (forcément MABB en V1).
        // Si plusieurs clubs actifs existaient, on prendrait le 1er
        // alphabétiquement — mais ce cas ne se produira qu'en V2 quand
        // ce listener sera remplacé par une page de choix.
        $clubs = $this->em->getRepository(Club::class)->findBy(
            ['isActive' => true],
            ['nom' => 'ASC']
        );
        if (empty($clubs)) {
            return; // Aucun club actif, on ne crée rien
        }

        $club = $clubs[0];

        // Création en status=PENDING : le user devra être validé par un
        // dirigeant avant d'avoir accès au manager. Empêche les inscriptions
        // sauvages et les voyeurs qui s'inscriraient dans plusieurs clubs.
        $userClubRole = new UserClubRole();
        $userClubRole->setUser($user);
        $userClubRole->setClub($club);
        $userClubRole->setRole(UserClubRole::ROLE_BENEVOLE);   // rôle "vide" en attente
        $userClubRole->setRoleDemande(UserClubRole::ROLE_BENEVOLE);
        $userClubRole->setStatus(UserClubRole::STATUS_PENDING);

        $this->em->persist($userClubRole);
        $this->em->flush();
    }
}
