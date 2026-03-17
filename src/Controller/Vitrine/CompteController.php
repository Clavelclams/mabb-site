<?php

namespace App\Controller\Vitrine;

use App\Entity\Core\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/compte')]
final class CompteController extends AbstractController
{
    /**
     * Page de connexion.
     *
     * IMPORTANT : Symfony gère automatiquement le traitement du formulaire POST
     * via le firewall `form_login` dans security.yaml.
     * Cette méthode ne gère QUE l'affichage (GET) et les erreurs.
     */
    #[Route('/se-connecter', name: 'vitrine_compte_se_connecter')]
    public function seConnecter(AuthenticationUtils $authUtils): Response
    {
        // Si déjà connecté, redirige vers l'accueil
        if ($this->getUser()) {
            return $this->redirectToRoute('vitrine_accueil');
        }

        return $this->render('vitrine/compte/se_connecter.html.twig', [
            // Dernier email saisi (réinjecté dans le form après erreur)
            'last_username' => $authUtils->getLastUsername(),
            // Message d'erreur si les identifiants sont mauvais
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Déconnexion — gérée automatiquement par Symfony via security.yaml.
     * Cette méthode ne sera jamais exécutée mais la route doit exister.
     */
    #[Route('/deconnexion', name: 'vitrine_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement. Symfony intercepte cette route.');
    }

    /**
     * Inscription : création d'un compte utilisateur.
     */
    #[Route('/s-inscrire', name: 'vitrine_compte_s_inscrire')]
    public function sInscrire(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        // Si déjà connecté, redirige
        if ($this->getUser()) {
            return $this->redirectToRoute('vitrine_accueil');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $prenom   = trim($request->request->get('prenom', ''));
            $nom      = trim($request->request->get('nom', ''));
            $email    = trim($request->request->get('email', ''));
            $password = $request->request->get('password', '');
            $confirm  = $request->request->get('password_confirm', '');
            $rgpd     = $request->request->getBoolean('rgpd_consent');

            // --- Validation manuelle (on n'utilise pas Symfony Form ici pour garder simple) ---
            if (empty($prenom)) {
                $errors[] = 'Le prénom est obligatoire.';
            }
            if (empty($nom)) {
                $errors[] = 'Le nom est obligatoire.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'L\'adresse email n\'est pas valide.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
            }
            if ($password !== $confirm) {
                $errors[] = 'Les mots de passe ne correspondent pas.';
            }
            if (!$rgpd) {
                $errors[] = 'Vous devez accepter la politique de confidentialité.';
            }

            // Vérifier que l'email n'est pas déjà utilisé
            if (empty($errors)) {
                $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existingUser) {
                    $errors[] = 'Un compte existe déjà avec cette adresse email.';
                }
            }

            // --- Création si pas d'erreur ---
            if (empty($errors)) {
                $user = new User();
                $user->setPrenom($prenom);
                $user->setNom($nom);
                $user->setEmail($email);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setRgpdConsent(true);

                $em->persist($user);
                $em->flush();

                $this->addFlash('success', 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.');
                return $this->redirectToRoute('vitrine_compte_se_connecter');
            }
        }

        return $this->render('vitrine/compte/s_inscrire.html.twig', [
            'errors' => $errors,
        ]);
    }

    #[Route('/mon-compte', name: 'vitrine_compte_mon_compte')]
    public function monCompte(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('vitrine/compte/mon_compte.html.twig');
    }

    #[Route('/update-profil', name: 'vitrine_compte_update_profil', methods: ['POST'])]
    public function updateProfil(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isCsrfTokenValid('update_profil', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('vitrine_compte_mon_compte');
        }

        /** @var \App\Entity\Core\User $user */
        $user = $this->getUser();

        $bio = $request->request->get('bio');
        $user->setBio($bio ? substr(strip_tags($bio), 0, 200) : null);

        $rolesValides = ['benevole', 'coach', 'staff', 'dirigeant', 'service-civique', 'joueur'];
        $roleMembre = $request->request->get('roleMembre');
        if (in_array($roleMembre, $rolesValides)) {
            $user->setRoleMembre($roleMembre);
        }

        $user->setIsPublic($request->request->has('isPublic'));

        $photoFile = $request->files->get('photo');
        if ($photoFile) {
            $safeFilename = $slugger->slug(pathinfo($photoFile->getClientOriginalName(), PATHINFO_FILENAME));
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $photoFile->guessExtension();
            try {
                $photoFile->move(
                    $this->getParameter('kernel.project_dir') . '/public/uploads/avatars',
                    $newFilename
                );
                $user->setPhotoPath($newFilename);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur upload photo.');
            }
        }

        $em->flush();
        $this->addFlash('success', 'Profil mis à jour ✅');

        return $this->redirectToRoute('vitrine_compte_mon_compte');
    }
}
