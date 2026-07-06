<?php

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\RencontreRole;
use App\Repository\Sport\RencontreRoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbRencontreController — auto-inscription bénévole depuis le PIRB.
 *
 * La joueuse peut s'inscrire comme marqueur / chrono / e-marque / stats
 * sur les prochains matchs de son équipe, directement depuis son dashboard.
 *
 * Différences par rapport au controller Manager équivalent :
 *  - Pas de ClubVoter (PIRB n'utilise pas encore TenantResolver — V1 mono-club).
 *  - Rôles ouverts aux joueuses : marqueur, chrono, emarque, stats.
 *    Arbitres et resp_salle sont réservés au Manager (staff désigne).
 *  - Inscription "en attente de validation staff" (present = false par défaut).
 *  - Tokens CSRF préfixés "pirb_" pour éviter toute collision avec Manager.
 */
#[Route('/rencontres', name: 'pirb_rencontre_')]
class PirbRencontreController extends AbstractController
{
    /**
     * Rôles que les joueuses peuvent s'auto-attribuer depuis le PIRB.
     * Les arbitres et resp_salle sont gérés uniquement par le staff (Manager).
     */
    private const ROLES_JOUEUSE = [
        RencontreRole::ROLE_MARQUEUR,
        RencontreRole::ROLE_CHRONO,
        RencontreRole::ROLE_EMARQUE,
        RencontreRole::ROLE_STATS,
    ];

    /**
     * POST /rencontres/{id}/benevole/{role}
     *
     * Inscription de la joueuse connectée à un rôle bénévole sur une rencontre.
     * Crée un RencontreRole avec present=false (validé ultérieurement par le staff).
     */
    #[Route('/{id}/benevole/{role}', name: 'sinscrire', methods: ['POST'], requirements: ['role' => 'arbitre_1|arbitre_2|resp_salle|marqueur|chrono|emarque|stats'])]
    public function sInscrire(
        Request $request,
        Rencontre $rencontre,
        string $role,
        RencontreRoleRepository $rencontreRoleRepo,
        EntityManagerInterface $em,
        \App\Repository\Sport\JoueurRepository $joueurRepo,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('pirb_login');
        }

        // [SÉCU 06/07/2026 — audit 29/06] ISOLATION MULTI-CLUB : cette route
        // acceptait N'IMPORTE QUEL id de rencontre — une joueuse du club A
        // pouvait s'inscrire bénévole sur un match du club B (IDOR).
        // Règle : la rencontre doit appartenir au club de la joueuse connectée.
        $monJoueur = $joueurRepo->findOneBy(['user' => $user]);
        if ($monJoueur === null
            || $monJoueur->getClub()?->getId() !== $rencontre->getClub()?->getId()) {
            throw $this->createAccessDeniedException('Ce match ne concerne pas ton club.');
        }

        // Vérification du rôle — seulement les rôles ouverts aux joueuses
        if (!in_array($role, self::ROLES_JOUEUSE, true)) {
            $this->addFlash('error', 'Ce rôle n\'est pas disponible depuis cet espace.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Validation CSRF — le token est généré dans le template via csrf_token('pirb_role_X_Y')
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('pirb_role_' . $rencontre->getId() . '_' . $role, $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Réessaie.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Pas d'inscription sur un match déjà passé (date + 4h)
        $limite = $rencontre->getDate()?->modify('+4 hours');
        if ($limite !== null && $limite < new \DateTimeImmutable()) {
            $this->addFlash('warning', 'Ce match est terminé, tu ne peux plus t\'inscrire.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Vérifier que la joueuse n'est pas déjà inscrite sur cette rencontre (toutes rôles)
        $dejaInscrite = $rencontreRoleRepo->findOneBy(['rencontre' => $rencontre, 'user' => $user]);
        if ($dejaInscrite !== null) {
            $this->addFlash('info', 'Tu es déjà inscrite comme ' . $dejaInscrite->getRoleLibelle() . ' sur ce match.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Vérifier que le rôle n'est pas déjà pris par quelqu'un d'autre
        $rolePris = $rencontreRoleRepo->findOneBy(['rencontre' => $rencontre, 'role' => $role]);
        if ($rolePris !== null) {
            $this->addFlash('info', sprintf(
                'Le rôle "%s" est déjà pris sur ce match.',
                RencontreRole::ROLE_LIBELLES[$role] ?? $role
            ));
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Création de l'inscription
        $rr = new RencontreRole();
        $rr->setRencontre($rencontre);
        $rr->setUser($user);
        $rr->setRole($role);
        // present = false par défaut : le staff valide après le match
        // (c'est à ce moment que la Mission gamification est créée)

        $em->persist($rr);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                '✅ Inscription enregistrée comme %s sur le match vs %s. En attente de validation staff.',
                RencontreRole::ROLE_LIBELLES[$role] ?? $role,
                $rencontre->getAdversaire() ?? 'l\'adversaire'
            )
        );

        return $this->redirectToRoute('pirb_dashboard');
    }

    /**
     * POST /rencontres/{id}/benevole/desinscrire
     *
     * La joueuse se désinscrit du rôle qu'elle avait pris sur cette rencontre.
     * Impossible si le staff a déjà validé sa présence (present = true).
     */
    #[Route('/{id}/benevole/desinscrire', name: 'desinscrire', methods: ['POST'])]
    public function seDesinscrire(
        Request $request,
        Rencontre $rencontre,
        RencontreRoleRepository $rencontreRoleRepo,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('pirb_login');
        }

        // Validation CSRF
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('pirb_desinscrire_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Réessaie.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Trouver l'inscription existante
        $rr = $rencontreRoleRepo->findOneBy(['rencontre' => $rencontre, 'user' => $user]);
        if ($rr === null) {
            $this->addFlash('info', 'Tu n\'étais pas inscrite sur ce match.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Impossible de se désinscrire si le staff a déjà validé la présence
        if ($rr->isPresent()) {
            $this->addFlash('warning', 'Ton rôle a déjà été validé par le staff après le match, la désinscription n\'est plus possible.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $em->remove($rr);
        $em->flush();

        $this->addFlash('success', 'Désinscription effectuée.');

        return $this->redirectToRoute('pirb_dashboard');
    }
}
