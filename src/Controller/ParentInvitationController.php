<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\ParentJoueur;
use App\Repository\Core\UserRepository;
use App\Service\Security\ParentInvitationManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [B30c 12/06/2026] Acceptation d'une invitation parent envoyée par mail.
 *
 * Route publique (host manager.mabb.fr) — pas d'auth requise pour accéder
 * au formulaire d'acceptation. Le token sécurise l'accès.
 *
 * Workflow :
 *   GET  /parent-invitation/{token} → formulaire signup pré-rempli
 *   POST /parent-invitation/{token} → crée User + UserClubRole PARENT + ParentJoueur ACTIVE
 */
class ParentInvitationController extends AbstractController
{
    public function __construct(
        private readonly ParentInvitationManager $invitationManager,
        private readonly UserRepository $userRepo,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/parent-invitation/{token}', name: 'parent_invitation_accept', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function accept(Request $request, string $token): Response
    {
        $invitation = $this->invitationManager->findValidByToken($token);

        if ($invitation === null) {
            return $this->render('parent_invitation/expired.html.twig');
        }

        // Si user déjà existant avec cet email, on tente la liaison sans signup
        $existingUser = $this->userRepo->findOneBy(['email' => $invitation->getEmailCible()]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('parent_invitation_' . $invitation->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirect($request->getUri());
            }

            $joueur = $invitation->getJoueur();
            $club = $joueur->getClub();

            if ($existingUser !== null) {
                // L'utilisateur a un compte → on crée juste le ParentJoueur ACTIVE
                $parent = $existingUser;
            } else {
                // Création du User
                $prenom = trim((string) $request->request->get('prenom', ''));
                $nom    = trim((string) $request->request->get('nom', ''));
                $password = (string) $request->request->get('password', '');

                if ($prenom === '' || $nom === '' || strlen($password) < 8) {
                    $this->addFlash('error', 'Tous les champs sont obligatoires (mdp 8 chars min).');
                    return $this->redirect($request->getUri());
                }

                $parent = new User();
                $parent->setEmail($invitation->getEmailCible());
                $parent->setPrenom($prenom);
                $parent->setNom($nom);
                $parent->setPassword($this->passwordHasher->hashPassword($parent, $password));
                $parent->setIsActive(true);
                $parent->setRgpdConsent(true);
                $parent->setRgpdConsentAt(new \DateTimeImmutable());
                // createdAt setté par PrePersist sur User
                $this->em->persist($parent);
            }

            // UserClubRole PARENT actif
            $ucr = new UserClubRole();
            $ucr->setUser($parent);
            $ucr->setClub($club);
            $ucr->setRole(UserClubRole::ROLE_PARENT);
            $ucr->setStatut(UserClubRole::STATUT_ACTIVE);
            $this->em->persist($ucr);

            // ParentJoueur ACTIVE (validation auto via token)
            $pj = new ParentJoueur();
            $pj->setParentUser($parent);
            $pj->setJoueur($joueur);
            $pj->setStatut(ParentJoueur::STATUT_ACTIVE);
            $pj->setDemandePar(ParentJoueur::DEMANDE_PAR_INVITATION);
            $pj->setValideAt(new \DateTimeImmutable());
            $this->em->persist($pj);

            // Marque l'invitation comme acceptée
            $this->em->flush();
            $this->invitationManager->accepter($invitation, $parent);

            $this->logger->info('Invitation parent acceptée et compte créé', [
                'invitation_id' => $invitation->getId(),
                'parent_user_id' => $parent->getId(),
                'joueur_id'      => $joueur->getId(),
            ]);

            return $this->render('parent_invitation/success.html.twig', [
                'joueur' => $joueur,
                'parent' => $parent,
            ]);
        }

        return $this->render('parent_invitation/accept.html.twig', [
            'invitation'     => $invitation,
            'existing_user'  => $existingUser,
            'joueur'         => $invitation->getJoueur(),
        ]);
    }
}
