<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Equipe;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Qui entraîne cette équipe.
 *
 * Sans ce lien, le rôle COACH est une étiquette sans portée : le coach voit toutes
 * les équipes du club, et aucune séance n'appartient à personne. C'est ce qui rendait
 * impossible d'afficher « tes entraînements », et surtout de savoir qui n'a pas fait
 * l'appel. On ne peut pas responsabiliser quelqu'un sur une séance qui n'est à
 * personne.
 *
 * Seuls les dirigeants assignent les coachs. Un coach ne se nomme pas lui-même sur
 * une équipe : ce serait se donner accès aux données de joueuses dont il n'a pas la
 * charge.
 */
class EquipeCoachController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Ajoute un coach à l'équipe.
     *
     * POST /manager/equipes/{id}/coachs/ajouter
     */
    #[Route('/equipes/{id}/coachs/ajouter', name: 'manager_equipe_coach_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouter(Request $request, Equipe $equipe): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $equipe);

        if (!$this->isCsrfTokenValid('equipe_coachs_' . $equipe->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Formulaire expiré, recommence.');

            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        $userId = (int) $request->request->get('user_id', 0);
        $user   = $userId > 0 ? $this->em->getRepository(User::class)->find($userId) : null;

        if ($user === null) {
            $this->addFlash('error', 'Personne introuvable.');

            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        // Garde-fou : on ne peut nommer coach que quelqu'un qui a le rôle COACH,
        // actif, DANS CE CLUB. Sans cette vérification, un identifiant bricolé dans
        // le formulaire donnerait à n'importe qui l'accès aux données d'une équipe.
        if (!$this->aLeRoleCoachDansCeClub($user, $equipe)) {
            $this->addFlash('error', 'Cette personne n\'a pas le rôle coach dans ce club.');

            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        if ($equipe->estCoache($user)) {
            $this->addFlash('warning', 'Cette personne coache déjà cette équipe.');

            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        $equipe->addCoach($user);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s coache maintenant %s.',
            trim($user->getPrenom() . ' ' . $user->getNom()),
            $equipe->getNom(),
        ));

        return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
    }

    /**
     * Retire un coach de l'équipe.
     *
     * POST /manager/equipes/{id}/coachs/{userId}/retirer
     */
    #[Route('/equipes/{id}/coachs/{userId}/retirer', name: 'manager_equipe_coach_remove', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function retirer(Request $request, Equipe $equipe, int $userId): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $equipe);

        if (!$this->isCsrfTokenValid('equipe_coachs_' . $equipe->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Formulaire expiré, recommence.');

            return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
        }

        $user = $this->em->getRepository(User::class)->find($userId);

        if ($user !== null && $equipe->estCoache($user)) {
            $equipe->removeCoach($user);
            $this->em->flush();
            $this->addFlash('success', 'Coach retiré de l\'équipe.');
        }

        return $this->redirectToRoute('manager_equipe_show', ['id' => $equipe->getId()]);
    }

    /**
     * Cette personne a-t-elle le rôle coach, actif, dans le club de cette équipe ?
     *
     * On interroge UserClubRole plutôt que les rôles Symfony globaux : le rôle est
     * porté par le couple (utilisateur, club), pas par l'utilisateur. Quelqu'un peut
     * être coach à la MABB et simple parent ailleurs.
     */
    private function aLeRoleCoachDansCeClub(User $user, Equipe $equipe): bool
    {
        $role = $this->em->getRepository(UserClubRole::class)->findOneBy([
            'user'   => $user,
            'club'   => $equipe->getClub(),
            'role'   => UserClubRole::ROLE_COACH,
            'status' => UserClubRole::STATUS_ACTIVE,
        ]);

        return $role !== null;
    }
}
