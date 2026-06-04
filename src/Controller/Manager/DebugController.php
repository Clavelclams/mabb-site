<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Repository\Sport\EquipeRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DebugController — endpoint TEMPORAIRE pour diagnostiquer un 404 ou erreur prod.
 *
 * ⚠️ À SUPPRIMER après debug. Expose des infos internes (user, club, voter)
 * qui ne doivent pas rester en prod sur le long terme.
 *
 * Usage : https://manager.mabb.fr/debug-equipe/1 → texte brut avec l'état
 *
 * Cette route bypass le rendering Twig et toute la complexité du Show controller
 * pour isoler l'origine du problème.
 */
class DebugController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EquipeRepository $equipeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/debug-equipe/{id}', name: 'manager_debug_equipe', requirements: ['id' => '\d+'])]
    public function debugEquipe(int $id): Response
    {
        $output = [];

        // === 1. User connecté
        $user = $this->getUser();
        $output[] = '=== USER ===';
        if ($user instanceof User) {
            $output[] = sprintf('ID: %d | Email: %s | Roles: %s',
                $user->getId(),
                $user->getEmail(),
                implode(',', $user->getRoles())
            );

            $output[] = '';
            $output[] = '=== USER CLUB ROLES ===';
            foreach ($user->getUserClubRoles() as $i => $ucr) {
                $output[] = sprintf('[%d] club_id=%d, role=%s, is_active=%s, status=%s',
                    $i,
                    $ucr->getClub()?->getId() ?? -1,
                    $ucr->getRole() ?? 'null',
                    $ucr->isActive() ? 'true' : 'false',
                    $ucr->getStatus()
                );
            }
        } else {
            $output[] = 'NO USER (not authenticated)';
        }

        // === 2. Equipe demandée
        $output[] = '';
        $output[] = '=== EQUIPE ===';
        $equipe = $this->equipeRepository->find($id);
        if ($equipe instanceof Equipe) {
            $output[] = sprintf('ID: %d | Nom: %s | Club ID: %d | Actif: %s',
                $equipe->getId(),
                $equipe->getNom(),
                $equipe->getClub()?->getId() ?? -1,
                $equipe->isActive() ? 'true' : 'false'
            );
        } else {
            $output[] = sprintf('EQUIPE ID=%d NON TROUVÉE !', $id);
        }

        // === 3. TenantResolver
        $output[] = '';
        $output[] = '=== TENANT RESOLVER ===';
        $clubsUser = $user instanceof User ? $this->tenantResolver->getUserClubs($user) : [];
        $output[] = sprintf('getUserClubs() count: %d', count($clubsUser));
        foreach ($clubsUser as $club) {
            $output[] = sprintf(' - Club ID=%d, Nom=%s', $club->getId(), $club->getNom());
        }

        $clubActif = $this->tenantResolver->getCurrentClub();
        $output[] = sprintf('getCurrentClub(): %s',
            $clubActif ? sprintf('ID=%d, %s', $clubActif->getId(), $clubActif->getNom()) : 'null'
        );

        // === 4. Voter checks
        if ($equipe instanceof Equipe) {
            $output[] = '';
            $output[] = '=== VOTER CHECKS sur Equipe ===';
            $output[] = sprintf('CLUB_MEMBER : %s', $this->isGranted(ClubVoter::CLUB_MEMBER, $equipe) ? 'YES' : 'NO');
            $output[] = sprintf('CLUB_STAFF  : %s', $this->isGranted(ClubVoter::CLUB_STAFF, $equipe)  ? 'YES' : 'NO');
            $output[] = sprintf('CLUB_ADMIN  : %s', $this->isGranted(ClubVoter::CLUB_ADMIN, $equipe)  ? 'YES' : 'NO');
        }

        return new Response(
            "<pre style='font-family:monospace; padding:20px; background:#f4f4f4;'>" .
            htmlspecialchars(implode("\n", $output)) .
            "</pre>"
        );
    }
}
