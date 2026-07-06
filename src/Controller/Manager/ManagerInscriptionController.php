<?php

namespace App\Controller\Manager;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Service\JoueurMatcherService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerInscriptionController — signup pour manager.mabb.fr.
 *
 * Page vitrine MULTI-CLUB de la plateforme MABB Manager :
 *   - L'utilisateur voit que la plateforme accueille N clubs basket potentiels
 *   - V1 : un seul club (MABB) est actif. Les autres choix sont visibles mais
 *     désactivés (opacité réduite, non cliquable) pour montrer la vision produit
 *     sans bloquer l'inscription
 *   - V2+ : l'option "Créer mon club" deviendra cliquable et permettra à un
 *     nouveau président d'asso de provisionner son club
 *
 * Workflow inscription V1 :
 *   1. User remplit prénom/nom/email/password
 *   2. Symfony crée le User dans la base partagée (vitrine + manager)
 *   3. On crée automatiquement un UserClubRole MABB role=BENEVOLE
 *      → permet l'accès au manager.mabb.fr (firewall manager passe)
 *      → mais le ClubVoter limitera les actions sensibles (création équipe,
 *        modification joueuses) qui exigent COACH/DIRIGEANT
 *   4. Redirection vers la page de connexion Manager
 *
 * Sécurité : pas de vérification d'email pour la V1 (sera ajouté plus tard
 * via un token de confirmation et MAILER OVH SMTP).
 */
class ManagerInscriptionController extends AbstractController
{
    /**
     * [B-206 06/07/2026] Alias historique : d'anciens liens/favoris pointaient
     * sur manager.mabb.fr/signup (404). Le lien fautif côté PIRB pointe déjà
     * vers /inscription — cette redirection permanente attrape le reste
     * (favoris, vieux emails, moteurs de recherche).
     */
    #[Route('/signup', name: 'manager_signup_legacy', methods: ['GET'])]
    public function signupLegacy(): Response
    {
        return $this->redirectToRoute('manager_inscription', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/inscription', name: 'manager_inscription', methods: ['GET', 'POST'])]
    public function inscription(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        JoueurMatcherService $joueurMatcher,
    ): Response {
        // Si déjà connecté, redirige vers le dashboard
        if ($this->getUser()) {
            return $this->redirectToRoute('manager_dashboard');
        }

        $errors = [];

        // Récupère les clubs actifs (V1 = MABB uniquement)
        $clubsActifs = $em->getRepository(Club::class)->findBy(['isActive' => true], ['nom' => 'ASC']);

        if ($request->isMethod('POST')) {
            $prenom   = trim((string) $request->request->get('prenom', ''));
            $nom      = trim((string) $request->request->get('nom', ''));
            $email    = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('password_confirm', '');
            $rgpd       = $request->request->getBoolean('rgpd_consent');
            $clubId     = (int) $request->request->get('club_id', 0);
            $roleDemande = (string) $request->request->get('role_demande', UserClubRole::ROLE_BENEVOLE);
            $licence    = trim((string) $request->request->get('licence', '')) ?: null;
            $telephone  = trim((string) $request->request->get('telephone', '')) ?: null;

            // Sécurité : valider le rôle demandé (sinon fallback BENEVOLE)
            if (!UserClubRole::isValidRole($roleDemande)) {
                $roleDemande = UserClubRole::ROLE_BENEVOLE;
            }

            // --- Validation ---
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
            if ($clubId === 0) {
                $errors[] = 'Sélectionne un club avant de créer ton compte.';
            }

            // Vérifie club existe et actif
            $club = null;
            if ($clubId > 0) {
                $club = $em->getRepository(Club::class)->find($clubId);
                if (!$club || !$club->isActive()) {
                    $errors[] = 'Club invalide ou inactif.';
                }
            }

            // Vérifie email pas déjà utilisé
            if (empty($errors)) {
                $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                if ($existing) {
                    $errors[] = 'Un compte existe déjà avec cette adresse email. Connecte-toi directement.';
                }
            }

            // --- Création User + UserClubRole ---
            if (empty($errors) && $club instanceof Club) {
                $user = new User();
                $user->setPrenom($prenom);
                $user->setNom($nom);
                $user->setEmail($email);
                $user->setPassword($passwordHasher->hashPassword($user, $password));
                $user->setRgpdConsent(true);

                // ====================================================
                // MATCH AUTO Joueur ↔ User
                // ====================================================
                // Si un Joueur en BDD correspond aux infos saisies (licence, email,
                // téléphone), on auto-link et on valide directement. Le user
                // récupère ainsi toute sa gamification accumulée (XP, badges,
                // missions) dès sa première connexion.
                $joueurMatche = $joueurMatcher->chercherJoueurCorrespondant(
                    $club,
                    $licence,
                    $email,
                    $telephone
                );

                $em->persist($user);

                $userClubRole = new UserClubRole();
                $userClubRole->setUser($user);
                $userClubRole->setClub($club);
                $userClubRole->setRoleDemande($roleDemande);

                if ($joueurMatche !== null) {
                    // ON MATCH ! Auto-validation + lien gamification
                    $joueurMatcher->lierUserAuJoueur($user, $joueurMatche);
                    $userClubRole->setRole(UserClubRole::ROLE_JOUEUR);
                    $userClubRole->setStatus(UserClubRole::STATUS_ACTIVE);
                    $em->persist($userClubRole);
                    $em->flush();

                    $this->addFlash('success', sprintf(
                        '🎯 Bienvenue %s ! Nous avons reconnu ta fiche joueuse — ton compte est validé immédiatement. Tu retrouves toute ta gamification (XP, badges) sur ton profil.',
                        $user->getPrenom()
                    ));
                    return $this->redirectToRoute('manager_login');
                }

                // Pas de match — workflow pending normal
                $userClubRole->setRole(UserClubRole::ROLE_BENEVOLE);
                $userClubRole->setStatus(UserClubRole::STATUS_PENDING);
                $em->persist($userClubRole);
                $em->flush();

                $this->addFlash('success', sprintf(
                    'Compte créé pour %s — ta demande d\'inscription en tant que %s est en cours de validation par un dirigeant. Tu seras notifié.',
                    $club->getNom(),
                    strtolower($roleDemande)
                ));
                return $this->redirectToRoute('manager_login');
            }
        }

        return $this->render('manager/inscription.html.twig', [
            'errors'        => $errors,
            'clubs_actifs'  => $clubsActifs,
        ]);
    }
}
