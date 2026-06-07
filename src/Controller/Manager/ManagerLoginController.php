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
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // Récupération du club actif pour alimenter le dashboard
        $club = $tenantResolver->getCurrentClub();
        $prochainSeances = [];
        $prochainRencontres = [];
        $prochainEvenements = [];
        $mesReunionsAVenir = [];
        $mesPvNonLus = [];
        // Feed "Pour toi" Phase 1 MVP — items personnalisés en tête de dashboard
        $feedItems = [];

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
            }
        }

        return $this->render('manager/dashboard.html.twig', [
            'club'                => $club,
            'prochain_seances'    => $prochainSeances,
            'prochain_rencontres' => $prochainRencontres,
            'prochain_evenements' => $prochainEvenements,
            'mes_reunions_avenir' => $mesReunionsAVenir,
            'mes_pv_non_lus'      => $mesPvNonLus,
            'feed_items'          => $feedItems,
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
