<?php

namespace App\Controller\Vitrine;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * [V2.4j 10/07/2026] UN SEUL COMPTE pour tout l'écosystème MABB :
 *   - un compte créé sur mabb.fr demande AUTOMATIQUEMENT l'accès au club MABB
 *     (UserClubRole BENEVOLE en attente de validation dirigeant) → il EST un
 *     compte Manager du club ;
 *   - un compte Manager du club MABB a automatiquement « son petit compte
 *     mabb.fr » (mêmes identifiants, même User) — mon-compte l'accueille ;
 *   - un compte Manager d'un AUTRE club n'a PAS d'espace mabb.fr : mabb.fr
 *     est le site DU club — il est redirigé proprement vers Manager.
 */
#[Route('/compte')]
final class CompteController extends AbstractController
{
    public function __construct(
        private readonly ClubRepository $clubRepository,
        #[Autowire(param: 'app.club_vitrine_slug')]
        private readonly string $clubVitrineSlug,
    ) {}

    /** Le club servi par la vitrine (MABB), ou null si base non provisionnée. */
    private function clubVitrine(): ?\App\Entity\Core\Club
    {
        return $this->clubRepository->findOneBy(['slug' => $this->clubVitrineSlug]);
    }

    /**
     * Lien d'appartenance de l'user au club de la vitrine (le plus « avancé » :
     * un rôle actif prime sur un pending), et détection d'autres clubs.
     *
     * @return array{ucr: ?UserClubRole, autres_clubs: bool}
     */
    private function appartenanceVitrine(User $user): array
    {
        $clubVitrine = $this->clubVitrine();
        $ucrMabb = null;
        $autresClubs = false;
        foreach ($user->getUserClubRoles() as $ucr) {
            if ($clubVitrine !== null && $ucr->getClub()?->getId() === $clubVitrine->getId()) {
                if ($ucrMabb === null || ($ucr->isStatusActive() && !$ucrMabb->isStatusActive())) {
                    $ucrMabb = $ucr;
                }
            } else {
                $autresClubs = true;
            }
        }
        return ['ucr' => $ucrMabb, 'autres_clubs' => $autresClubs];
    }
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

                // [V2.4j] UN compte mabb.fr = UN compte Manager du club MABB.
                // On crée la demande d'adhésion au club (BENEVOLE, en attente
                // de validation dirigeant — même workflow que l'inscription
                // Manager). L'utilisateur n'a RIEN à refaire ailleurs.
                $clubVitrine = $this->clubVitrine();
                if ($clubVitrine !== null) {
                    $ucr = new UserClubRole();
                    $ucr->setUser($user);
                    $ucr->setClub($clubVitrine);
                    $ucr->setRole(UserClubRole::ROLE_BENEVOLE);
                    $ucr->setStatus(UserClubRole::STATUS_PENDING);
                    $em->persist($ucr);
                }

                $em->flush();

                $this->addFlash('success', 'Compte créé ! Connecte-toi — les mêmes identifiants marchent aussi sur MABB Manager et PIRB.');
                return $this->redirectToRoute('vitrine_compte_se_connecter');
            }
        }

        return $this->render('vitrine/compte/s_inscrire.html.twig', [
            'errors' => $errors,
        ]);
    }

    #[Route('/mon-compte', name: 'vitrine_compte_mon_compte')]
    public function monCompte(\App\Repository\Sport\JoueurRepository $joueurRepo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        $app = $this->appartenanceVitrine($user);

        // [V2.4j] GATE : un membre d'AUTRES clubs uniquement (manager externe)
        // n'a pas d'espace mabb.fr — le site EST celui du club MABB. Accueil
        // propre + renvoi vers son vrai espace (Manager). Les super-admins
        // passent (support).
        if ($app['ucr'] === null && $app['autres_clubs'] && !$this->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->render('vitrine/compte/hors_club.html.twig', [
                'club_vitrine' => $this->clubVitrine(),
            ]);
        }

        // Statut d'appartenance au club MABB pour la card écosystème :
        //   'active'  → membre validé (accès Manager complet selon rôle)
        //   'pending' → demande en attente de validation dirigeant
        //   'aucun'   → compte vitrine pur, pas encore rattaché
        $statutMabb = 'aucun';
        if ($app['ucr'] !== null) {
            $statutMabb = $app['ucr']->isStatusActive() ? 'active' : ($app['ucr']->isPending() ? 'pending' : 'aucun');
        }

        return $this->render('vitrine/compte/mon_compte.html.twig', [
            'statut_mabb' => $statutMabb,
            'role_mabb'   => $app['ucr']?->getRole(),
            // Fiche joueuse liée → l'espace PIRB a du sens pour elle
            'joueuse'     => $joueurRepo->findOneBy(['user' => $user]),
        ]);
    }

    /**
     * [V2.4j] Rattachement au club MABB en 1 clic depuis mon-compte
     * (comptes vitrine créés AVANT l'auto-rattachement, ou demande refusée
     * à refaire). Même workflow : BENEVOLE en attente de validation.
     */
    #[Route('/rejoindre-le-club', name: 'vitrine_compte_rejoindre_club', methods: ['POST'])]
    public function rejoindreClub(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('rejoindre_club', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée — réessaie.');
            return $this->redirectToRoute('vitrine_compte_mon_compte');
        }

        $clubVitrine = $this->clubVitrine();
        $app = $this->appartenanceVitrine($user);
        if ($clubVitrine === null || $app['ucr'] !== null) {
            return $this->redirectToRoute('vitrine_compte_mon_compte');
        }

        $ucr = new UserClubRole();
        $ucr->setUser($user);
        $ucr->setClub($clubVitrine);
        $ucr->setRole(UserClubRole::ROLE_BENEVOLE);
        $ucr->setStatus(UserClubRole::STATUS_PENDING);
        $em->persist($ucr);
        $em->flush();

        $this->addFlash('success', 'Demande envoyée au club ! Un dirigeant va la valider — tu auras alors accès à MABB Manager.');
        return $this->redirectToRoute('vitrine_compte_mon_compte');
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

        // Rôles vitrine multi-sélection — benevole toujours forcé par setRolesMembre()
        $rolesValides = ['coach', 'staff', 'dirigeant', 'service-civique', 'joueur', 'parent'];
        $rolesCoches = $request->request->all('rolesMembre') ?? [];
        $rolesCoches = array_filter($rolesCoches, fn($r) => in_array($r, $rolesValides));
        $user->setRolesMembre(array_values($rolesCoches)); // benevole sera auto-injecté

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
