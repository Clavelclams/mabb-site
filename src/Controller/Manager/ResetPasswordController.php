<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Form\Security\ResetPasswordChangeType;
use App\Form\Security\ResetPasswordRequestType;
use App\Service\Security\ResetPasswordTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B1 — Sécu jury : workflow reset password sur manager.mabb.fr.
 *
 * Routes :
 *   GET  /mot-de-passe-oublie         → formulaire email
 *   POST /mot-de-passe-oublie         → traite la demande, envoie mail
 *   GET  /mot-de-passe-oublie/envoye  → page "vérifiez votre boîte mail"
 *   GET  /reinitialiser/{token}       → formulaire nouveau password
 *   POST /reinitialiser/{token}       → applique le nouveau password
 *
 * Accès : public (anonymous_access). Pas de filtre tenant : un user peut
 * appartenir à plusieurs clubs, on reset l'user globalement.
 *
 * Anti-énumération : la page "envoye" est affichée SYSTÉMATIQUEMENT,
 * même si l'email entré n'existe pas. Le caller ne saura jamais si
 * son email est en base ou pas (RGPD-safe).
 */
class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly ResetPasswordTokenManager $tokenManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Étape 1 : formulaire de demande de reset.
     * Affiche un champ email. À la soumission, on appelle le tokenManager.
     */
    #[Route('/mot-de-passe-oublie', name: 'manager_reset_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        // Si déjà connecté, pas la peine de reset
        if ($this->getUser()) {
            return $this->redirectToRoute('manager_dashboard');
        }

        $form = $this->createForm(ResetPasswordRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $form->get('email')->getData();

            try {
                $ok = $this->tokenManager->requestReset($email, $request);

                if (!$ok) {
                    // Rate-limit atteint
                    $this->addFlash('warning', 'Trop de demandes ces dernières minutes. Réessayez dans 15 minutes.');
                    return $this->redirectToRoute('manager_reset_password_request');
                }
            } catch (\RuntimeException $e) {
                // Échec mailer — on log et on affiche un message générique
                $this->logger->error('Erreur reset_password request', ['error' => $e->getMessage()]);
                $this->addFlash('error', 'Impossible d\'envoyer le mail pour le moment. Réessayez plus tard.');
                return $this->redirectToRoute('manager_reset_password_request');
            }

            // Toujours rediriger vers "envoyé" même si email inconnu (anti-énumération)
            return $this->redirectToRoute('manager_reset_password_sent');
        }

        return $this->render('manager/security/reset_password_request.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Page intermédiaire affichée après la demande.
     * Volontairement générique : ne révèle pas si l'email existe.
     */
    #[Route('/mot-de-passe-oublie/envoye', name: 'manager_reset_password_sent', methods: ['GET'])]
    public function sent(): Response
    {
        return $this->render('manager/security/reset_password_sent.html.twig');
    }

    /**
     * Étape 2 : l'user clique sur le lien dans le mail.
     * On valide le token et on affiche le formulaire de nouveau password.
     */
    #[Route('/reinitialiser/{token}', name: 'manager_reset_password_reset', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function reset(Request $request, string $token): Response
    {
        $resetRequest = $this->tokenManager->findValidRequest($token);

        if ($resetRequest === null) {
            $this->logger->warning('Tentative reset avec token invalide ou expiré', [
                'ip' => $request->getClientIp(),
            ]);
            return $this->render('manager/security/reset_password_expired.html.twig');
        }

        $user = $resetRequest->getUser();
        if ($user === null || !$user->isActive()) {
            return $this->render('manager/security/reset_password_expired.html.twig');
        }

        $form = $this->createForm(ResetPasswordChangeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();

            // Hash le nouveau password avec l'algo configuré dans security.yaml
            // (bcrypt cost 13 par défaut chez Clavel — voir security.yaml)
            $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

            // Consomme le token (irréversible — il ne peut plus servir)
            $this->tokenManager->consume($resetRequest);

            // Persist user (le service consume a déjà flush le rpr)
            $this->em->flush();

            $this->logger->info('Password réinitialisé avec succès', [
                'user_id' => $user->getId(),
                'ip'      => $request->getClientIp(),
            ]);

            $this->addFlash('success', '✅ Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.');
            return $this->redirectToRoute('manager_login');
        }

        return $this->render('manager/security/reset_password_change.html.twig', [
            'form' => $form,
        ]);
    }
}
