<?php

namespace App\Controller\Manager;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * ManagerLoginController — authentification pour manager.mabb.fr.
 *
 * Ce controller est routé uniquement sur le host manager.mabb.fr
 * via config/routes/manager.yaml (contrainte host).
 *
 * Le firewall "manager" dans security.yaml intercepte les POST
 * sur /login pour traiter l'authentification automatiquement.
 */
class ManagerLoginController extends AbstractController
{
    /**
     * Page de connexion — manager.mabb.fr/login
     *
     * GET  → affiche le formulaire de login
     * POST → intercepté par Symfony (firewall "manager"), jamais traité ici
     */
    #[Route('/login', name: 'manager_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        // Déjà connecté → accueil manager
        if ($this->getUser()) {
            return $this->redirectToRoute('manager_dashboard');
        }

        return $this->render('manager/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Dashboard principal — manager.mabb.fr/
     *
     * Point d'entrée après connexion. Protégé par access_control (ROLE_USER min).
     * Cette page sera enrichie au fil des sprints (affichage du club, équipes...).
     */
    #[Route('/', name: 'manager_dashboard')]
    public function dashboard(
        \App\Security\Tenant\TenantResolver $tenantResolver,
        \App\Repository\Sport\SeanceRepository $seanceRepository,
        \App\Repository\Sport\RencontreRepository $rencontreRepository,
        \App\Repository\Sport\ReunionConvocationRepository $convocationRepository,
        \App\Repository\Sport\EvenementRepository $evenementRepository,
        \App\Service\FeedAggregator $feedAggregator,
        \App\Repository\Sport\NoteFraisRepository $noteFraisRepository,
        // [V2.4k] espace MEMBRE (bénévole/parent) : missions + enfants
        \App\Repository\Sport\AffectationMatchRepository $affectationRepository,
        \App\Repository\Sport\ParentJoueurRepository $parentJoueurRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // Récupération du club actif pour alimenter le dashboard
        $club = $tenantResolver->getCurrentClub();

        // Super-admin (ex. admin@velito.fr) sans club actif → il n'a pas de club
        // à lui : on l'envoie sur la console cross-club pour qu'il en choisisse un.
        if (!$club && $tenantResolver->isSuperAdmin()) {
            return $this->redirectToRoute('manager_super_admin_clubs');
        }
        $prochainSeances = [];
        $prochainRencontres = [];
        $prochainEvenements = [];
        $mesReunionsAVenir = [];
        $mesPvNonLus = [];
        // [V2.4k] espace membre
        $mesMissions = [];
        $postesVacants = [];
        $mesEnfants = [];
        // Feed "Pour toi" Phase 1 MVP — items personnalisés en tête de dashboard
        $feedItems = [];
        // Compteur pour le badge "X en attente" sur la card trésorier (D.2)
        $nbNotesAValider = 0;

        if ($club) {
            $now = new \DateTimeImmutable();
            $dans7Jours = $now->modify('+7 days');

            // Prochaines séances dans les 7 jours
            $prochainSeances = $seanceRepository->createQueryBuilder('s')
                ->where('s.club = :club')
                ->andWhere('s.date BETWEEN :now AND :j7')
                ->setParameter('club', $club)
                ->setParameter('now', $now)
                ->setParameter('j7', $dans7Jours)
                ->orderBy('s.date', 'ASC')
                ->setMaxResults(5)
                ->getQuery()->getResult();

            // Prochaines rencontres dans les 7 jours
            $prochainRencontres = $rencontreRepository->createQueryBuilder('r')
                ->where('r.club = :club')
                ->andWhere('r.date BETWEEN :now AND :j7')
                ->setParameter('club', $club)
                ->setParameter('now', $now)
                ->setParameter('j7', $dans7Jours)
                ->orderBy('r.date', 'ASC')
                ->setMaxResults(5)
                ->getQuery()->getResult();

            // Prochains événements dans les 7 jours (publiés uniquement)
            $prochainEvenements = $evenementRepository->createQueryBuilder('e')
                ->where('e.club = :club')
                ->andWhere('e.date BETWEEN :now AND :j7')
                ->andWhere('e.statut = :publie')
                ->setParameter('club', $club)
                ->setParameter('now', $now)
                ->setParameter('j7', $dans7Jours)
                ->setParameter('publie', \App\Entity\Sport\Evenement::STATUT_PUBLIE)
                ->orderBy('e.date', 'ASC')
                ->setMaxResults(5)
                ->getQuery()->getResult();

            // === BUREAU MANAGER Phase C — retour membre ===
            // Mes réunions à venir où je suis convoqué (badge "X réunions à venir")
            // Mes PV non lus (badge "X nouveau(x) PV")
            $userConnecte = $this->getUser();
            if ($userConnecte instanceof \App\Entity\Core\User) {
                $mesReunionsAVenir = $convocationRepository->findMesReunionsAVenir($userConnecte, $club);
                $mesPvNonLus       = $convocationRepository->findPvNonLus($userConnecte, $club);

                // Feed "Pour toi" — agrégation déléguée au FeedAggregator (SRP).
                // Le controller ne sait pas COMMENT le feed est construit, il
                // appelle juste le service et passe le résultat à la vue.
                $feedItems = $feedAggregator->buildForUser($userConnecte, $club);

                // [V2.4k] Espace MEMBRE — pour que bénévoles et parents aient
                // un dashboard qui leur parle (pas seulement des cards staff) :
                //   - mes prochaines missions (chrono, buvette… où je suis affecté)
                //   - les postes à pourvoir bientôt (un bénévole peut candidater)
                //   - mes enfants liés (fiche + bilan à un clic)
                $mesMissions = $affectationRepository->findMissionsAVenir($userConnecte);
                // ⚠️ findRencontresAvecRolesVacants ne filtre PAS par club
                // (méthode cross-club historique) → isolation multi-tenant
                // appliquée ICI, sinon la card afficherait les matchs des
                // autres clubs. Limité à 5 pour la card.
                $postesVacants = array_slice(array_values(array_filter(
                    $affectationRepository->findRencontresAvecRolesVacants($now),
                    fn($r) => $r->getClub()?->getId() === $club->getId()
                )), 0, 5);
                $mesEnfants = $parentJoueurRepository->findEnfantsActifs($userConnecte);
            }

            // Badge "X notes à valider" — utile uniquement pour TRESORIER/SUPER_ADMIN
            // mais on calcule pour tous (la card n'est affichée que pour eux).
            // Coût : 1 COUNT(*) SQL → négligeable.
            $nbNotesAValider = $noteFraisRepository->countEnAttente($club);
        }

        // Les séances passées de ce coach dont l'appel n'a jamais été fait.
        //
        // C'est le seul endroit où l'oubli devient visible. Sans ce bandeau, un appel
        // non fait ne se remarque jamais : la séance est passée, personne ne revient
        // dessus, et la présence de la joueuse est perdue pour de bon (or elle
        // alimente ses badges et son XP).
        //
        // Non bloquant, volontairement. Ce sont des bénévoles : on rappelle, on
        // n'enferme pas.
        $seancesSansAppel = [];
        if ($club) {
            $seancesSansAppel = $seanceRepository->findSansAppelPourCoach(
                $this->getUser(),
                $club,
            );
        }

        return $this->render('manager/dashboard.html.twig', [
            'club'                 => $club,
            'prochain_seances'     => $prochainSeances,
            'prochain_rencontres'  => $prochainRencontres,
            'prochain_evenements'  => $prochainEvenements,
            'mes_reunions_avenir'  => $mesReunionsAVenir,
            'mes_pv_non_lus'       => $mesPvNonLus,
            'feed_items'           => $feedItems,
            'nb_notes_a_valider'   => $nbNotesAValider,
            'seances_sans_appel'   => $seancesSansAppel,
            // [V2.4k] espace membre (bénévole / parent)
            'mes_missions'         => $mesMissions,
            'postes_vacants'       => $postesVacants,
            'mes_enfants'          => $mesEnfants,
            'is_staff'             => $club && $this->isGranted(\App\Security\Voter\ClubVoter::CLUB_STAFF, $club),
        ]);
    }

    /**
     * [V2.4k] GUIDE « Ma première fois sur le site » — page d'accueil pédagogique.
     * Adaptée au rôle (membre / staff / secrétariat) : chaque section explique
     * UN geste avec le lien direct pour le faire. Accessible en permanence via
     * le menu utilisateur (❓ Guide) + bannière première visite du dashboard.
     */
    #[Route('/bienvenue', name: 'manager_bienvenue', methods: ['GET'])]
    public function bienvenue(\App\Security\Tenant\TenantResolver $tenantResolver): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $club = $tenantResolver->getCurrentClub();

        return $this->render('manager/bienvenue.html.twig', [
            'club'           => $club,
            'is_staff'       => $club && $this->isGranted(\App\Security\Voter\ClubVoter::CLUB_STAFF, $club),
            'is_secretariat' => $club && $this->isGranted(\App\Security\Voter\ClubVoter::CLUB_SECRETARIAT, $club),
        ]);
    }

    /**
     * Déconnexion — interceptée automatiquement par Symfony.
     * Cette méthode n'est jamais exécutée, la route doit juste exister.
     */
    #[Route('/deconnexion', name: 'manager_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement.');
    }
}
