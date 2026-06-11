<?php

namespace App\Controller\Manager;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use App\Repository\Core\ConnexionLogRepository;
use App\Repository\Core\RgpdRequestRepository;
use App\Repository\Sport\JoueurRepository;
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
 * Cette page affiche les infos perso, les rôles dans le club actif,
 * la fiche joueuse attachée (si existante) et donne les accès aux actions
 * personnelles (changer mdp, télécharger données RGPD, etc.).
 *
 * Préparation du futur switch de mode (Uber-style) : cette page est le
 * "home" du mode "Mon profil" parmi les modes possibles (Joueur, Coach,
 * Dirigeant, Bénévole) qui s'activeront selon les rôles cumulés.
 */
class ProfilController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly JoueurRepository $joueurRepository,
        private readonly RgpdRequestRepository $rgpdRepo,
        private readonly ConnexionLogRepository $logRepo,
        private readonly RgpdExporter $rgpdExporter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/profil', name: 'manager_profil', methods: ['GET'])]
    public function show(): Response
    {
        // ====================================================================
        // Récupération de l'utilisateur connecté
        // $this->getUser() retourne le User authentifié, jamais null ici
        // car le firewall manager force l'auth sur cette route.
        // ====================================================================
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Garde-fou : si l'user n'est pas du bon type (cas pathologique),
            // on redirige vers login plutôt que de planter avec un null deref.
            return $this->redirectToRoute('manager_login');
        }

        $club = $this->tenantResolver->getCurrentClub();

        // ====================================================================
        // Rôles de l'utilisateur DANS LE CLUB actif (UserClubRole)
        // Distinction importante :
        //   - User->roles (Symfony) = ['ROLE_USER', 'ROLE_SUPER_ADMIN']
        //   - UserClubRole         = ['COACH', 'DIRIGEANT'] dans tel club
        // On filtre car un user peut avoir des rôles dans plusieurs clubs.
        // ====================================================================
        $rolesDansClub = [];
        if ($club) {
            foreach ($user->getUserClubRoles() as $ucr) {
                if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isActive()) {
                    $rolesDansClub[] = $ucr;
                }
            }
        }

        // ====================================================================
        // Fiche Joueur attachée au compte (si l'user est une joueuse)
        // Une joueuse peut avoir un User attaché (cas majeur) ou pas (mineure).
        // On cherche dans le club actif uniquement.
        // ====================================================================
        $ficheJoueur = null;
        if ($club) {
            $ficheJoueur = $this->joueurRepository->findOneBy([
                'user' => $user,
                'club' => $club,
            ]);
        }

        // B2 : dernière connexion + demande RGPD en cours
        $derniereConnexion = $this->logRepo->findLastSuccessForUser($user);
        $rgpdPending = $this->rgpdRepo->findActivePendingForUser($user);

        return $this->render('manager/profil/show.html.twig', [
            'user'             => $user,
            'club'             => $club,
            'roles_in_club'    => $rolesDansClub,
            'fiche_joueur'     => $ficheJoueur,
            'derniere_connexion' => $derniereConnexion,
            'rgpd_pending'     => $rgpdPending,
        ]);
    }

    /**
     * B2 — Action user : demander l'effacement de mes données (Art. 17 RGPD).
     * Crée une RgpdRequest pending qu'un admin devra valider.
     */
    #[Route('/profil/rgpd/demande-effacement', name: 'manager_profil_rgpd_effacement', methods: ['POST'])]
    public function demanderEffacement(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('rgpd_effacement_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_profil');
        }

        // Une seule demande active par user — anti-spam
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

    /**
     * B2 — Action user : télécharger mes données (Art. 15 RGPD).
     * Self-service : pas besoin de demande admin pour ses propres données.
     */
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
}
