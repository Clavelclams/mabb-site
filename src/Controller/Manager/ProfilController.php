<?php

namespace App\Controller\Manager;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ConnexionLogRepository;
use App\Repository\Core\RgpdRequestRepository;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Service\Rgpd\RgpdExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ProfilController — espace personnel de l'utilisateur connecté.
 *
 * La page est ADAPTATIVE selon les rôles métier :
 *   - JOUEUR    → bloc "Ma fiche joueuse" avec lien vers stats
 *   - COACH     → bloc "Mes équipes" (CoachEquipe)
 *   - PARENT    → bloc "Mon enfant" avec profil enfant (stats selon profilPublic)
 *   - autres    → bloc engagement générique avec liens utiles
 *
 * Les rôles ne sont pas exclusifs : un user peut être COACH + PARENT.
 * Chaque bloc s'affiche dès que le rôle correspondant est actif.
 *
 * L'édition des infos perso (prénom, nom, tel, ddn, bio) est sur /profil/edit.
 */
class ProfilController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver          $tenantResolver,
        private readonly JoueurRepository        $joueurRepository,
        private readonly RgpdRequestRepository   $rgpdRepo,
        private readonly ConnexionLogRepository  $logRepo,
        private readonly RgpdExporter            $rgpdExporter,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
        private readonly CoachEquipeRepository   $coachEquipeRepo,
        private readonly ParentJoueurRepository  $parentJoueurRepo,
        private readonly \App\Service\SaisonService $saisonService,
    ) {}

    // =========================================================================
    // Affichage
    // =========================================================================

    #[Route('/profil', name: 'manager_profil', methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_login');
        }

        $club = $this->tenantResolver->getCurrentClub();

        // ----------------------------------------------------------------
        // Rôles métier actifs dans le club courant
        // ----------------------------------------------------------------
        $rolesDansClub = [];
        if ($club) {
            foreach ($user->getUserClubRoles() as $ucr) {
                if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isActive()) {
                    $rolesDansClub[] = $ucr;
                }
            }
        }

        // Raccourcis booléens par type de rôle — on s'en sert dans le template
        // et pour décider quoi charger depuis la BDD.
        $codesRoles = array_map(fn($ucr) => $ucr->getRole(), $rolesDansClub);
        $isJoueur   = in_array(UserClubRole::ROLE_JOUEUR, $codesRoles, true);
        $isCoach    = in_array(UserClubRole::ROLE_COACH, $codesRoles, true);
        $isParent   = in_array(UserClubRole::ROLE_PARENT, $codesRoles, true);

        // ----------------------------------------------------------------
        // Fiche joueuse (si JOUEUR ou si la fiche existe sans UCR — cohérence data)
        // ----------------------------------------------------------------
        $ficheJoueur = null;
        if ($club && ($isJoueur || true)) {
            // On cherche toujours (pas seulement si isJoueur) : un user peut
            // avoir une fiche joueuse existante sans avoir le UCR JOUEUR actif.
            $ficheJoueur = $this->joueurRepository->findOneBy([
                'user' => $user,
                'club' => $club,
            ]);
        }

        // ----------------------------------------------------------------
        // Coach → équipes coachées (saison courante en tête)
        // ----------------------------------------------------------------
        $equipesCoachees = [];
        if ($isCoach) {
            $saisonCourante  = $this->saisonCourante();
            // On charge TOUTES les saisons ; on les trie : saison courante first,
            // puis ordre alpha équipe. Affichage dans le template.
            $equipesCoachees = $this->coachEquipeRepo->findByCoach($user);
        }

        // ----------------------------------------------------------------
        // Parent → enfants actifs (avec leur fiche joueuse pour le mini-profil)
        // ----------------------------------------------------------------
        $enfants = [];
        if ($isParent) {
            $enfants = $this->parentJoueurRepo->findEnfantsActifs($user);
        }

        // ----------------------------------------------------------------
        // Divers : dernière connexion + RGPD
        // ----------------------------------------------------------------
        $derniereConnexion = $this->logRepo->findLastSuccessForUser($user);
        $rgpdPending       = $this->rgpdRepo->findActivePendingForUser($user);

        return $this->render('manager/profil/show.html.twig', [
            'user'               => $user,
            'club'               => $club,
            'roles_in_club'      => $rolesDansClub,
            'codes_roles'        => $codesRoles,
            'is_joueur'          => $isJoueur,
            'is_coach'           => $isCoach,
            'is_parent'          => $isParent,
            'fiche_joueur'       => $ficheJoueur,
            'equipes_coachees'   => $equipesCoachees,
            'saison_courante'    => $this->saisonCourante(),
            'enfants'            => $enfants,
            'derniere_connexion' => $derniereConnexion,
            'rgpd_pending'       => $rgpdPending,
        ]);
    }

    // =========================================================================
    // Édition des infos personnelles
    // =========================================================================

    /**
     * GET  → formulaire pré-rempli
     * POST → validation + sauvegarde
     *
     * Champs éditables : prénom, nom, téléphone, date de naissance, bio.
     * L'email n'est PAS éditable ici (identifiant de sécurité — autre workflow).
     * Le mot de passe a son propre workflow (reset_password).
     */
    #[Route('/profil/edit', name: 'manager_profil_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_login');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            // Vérification CSRF
            if (!$this->isCsrfTokenValid('profil_edit_' . $user->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide. Réessaie.');
                return $this->redirectToRoute('manager_profil_edit');
            }

            // Récupération et nettoyage des valeurs
            $prenom = trim((string) $request->request->get('prenom', ''));
            $nom    = trim((string) $request->request->get('nom', ''));
            $tel    = trim((string) $request->request->get('telephone', ''));
            $ddnStr = trim((string) $request->request->get('dateNaissance', ''));
            $bio    = trim((string) $request->request->get('bio', ''));

            // Validation simple (pas de Symfony Form pour garder ça léger)
            if ($prenom === '') {
                $errors['prenom'] = 'Le prénom est obligatoire.';
            } elseif (strlen($prenom) > 100) {
                $errors['prenom'] = 'Prénom trop long (100 caractères max).';
            }

            if ($nom === '') {
                $errors['nom'] = 'Le nom est obligatoire.';
            } elseif (strlen($nom) > 100) {
                $errors['nom'] = 'Nom trop long (100 caractères max).';
            }

            // Téléphone : optionnel, format libre, max 20 caractères
            if ($tel !== '' && strlen($tel) > 20) {
                $errors['telephone'] = 'Numéro trop long (20 caractères max).';
            }

            // Date de naissance : optionnelle, format attendu YYYY-MM-DD
            $dateNaissance = null;
            if ($ddnStr !== '') {
                try {
                    $dateNaissance = new \DateTimeImmutable($ddnStr);
                    // Sanity check : pas de date dans le futur, pas avant 1900
                    if ($dateNaissance > new \DateTimeImmutable()) {
                        $errors['dateNaissance'] = 'Date de naissance dans le futur.';
                        $dateNaissance = null;
                    } elseif ($dateNaissance < new \DateTimeImmutable('1900-01-01')) {
                        $errors['dateNaissance'] = 'Date de naissance invalide.';
                        $dateNaissance = null;
                    }
                } catch (\Exception) {
                    $errors['dateNaissance'] = 'Format de date invalide (attendu : AAAA-MM-JJ).';
                }
            }

            if (empty($errors)) {
                $user->setPrenom($prenom);
                $user->setNom($nom);
                $user->setTelephone($tel !== '' ? $tel : null);
                $user->setDateNaissance($dateNaissance);
                $user->setBio($bio !== '' ? $bio : null);

                $this->em->flush();

                $this->logger->info('Profil mis à jour par l\'utilisateur', ['user_id' => $user->getId()]);

                $this->addFlash('success', '✅ Profil mis à jour.');
                return $this->redirectToRoute('manager_profil');
            }
        }

        return $this->render('manager/profil/edit.html.twig', [
            'user'   => $user,
            'errors' => $errors,
        ]);
    }

    // =========================================================================
    // RGPD
    // =========================================================================

    #[Route('/profil/rgpd/demande-effacement', name: 'manager_profil_rgpd_effacement', methods: ['POST'])]
    public function demanderEffacement(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('rgpd_effacement_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_profil');
        }

        if ($this->rgpdRepo->findActivePendingForUser($user) !== null) {
            $this->addFlash('warning', 'Une demande est déjà en cours de traitement.');
            return $this->redirectToRoute('manager_profil');
        }

        $motif = trim((string) $request->request->get('motif_user', ''));
        $req = new RgpdRequest($user, RgpdRequest::TYPE_EFFACEMENT);
        $req->setMotifUser($motif ?: null);
        $this->em->persist($req);
        $this->em->flush();

        $this->logger->info('Demande RGPD effacement créée', [
            'rgpd_id' => $req->getId(),
            'user_id' => $user->getId(),
        ]);

        $this->addFlash('success', '✅ Demande enregistrée. Un admin la traitera sous 30 jours (délai légal RGPD).');
        return $this->redirectToRoute('manager_profil');
    }

    #[Route('/profil/rgpd/export.json', name: 'manager_profil_rgpd_export', methods: ['GET'])]
    public function exportMesDonnees(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = $this->rgpdExporter->exportUserData($user);
        $response = new JsonResponse($data);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="mabb_mes_donnees_%s.json"',
            (new \DateTimeImmutable())->format('Y-m-d')
        ));
        $this->logger->info('Export RGPD self-service', ['user_id' => $user->getId()]);
        return $response;
    }

    // =========================================================================
    // Utilitaires privés
    // =========================================================================

    /**
     * Calcule la saison sportive courante.
     * La saison commence en septembre (mois ≥ 9 → on est dans l'année de début).
     * Ex : août 2026 → saison 2025-2026 | novembre 2026 → saison 2026-2027
     */
    /**
     * [V2.4 05/07/2026] Délègue à SaisonService (sélecteur global +
     * bascule auto 1er juillet) — fin de la logique dupliquée.
     */
    private function saisonCourante(): string
    {
        return $this->saisonService->getSaisonActive();
    }
}
