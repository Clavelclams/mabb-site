<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\ParentJoueur;
use App\Repository\Core\UserRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [B30b 12/06/2026] PIRB Mes parents — la joueuse déclare ses parents/référents.
 *
 * Différent de B24 (Mes enfants côté parent) : ici c'est la JOUEUSE qui initie.
 * Workflow : joueuse cherche un User dans le club par nom/email → crée
 * ParentJoueur statut=PENDING avec demandePar=JOUEUR → staff valide.
 *
 * Utile pour les ados qui veulent eux-mêmes faire la déclaration sans
 * attendre que leur parent télécharge l'app et fasse la demande.
 *
 * RGPD : on accepte que la joueuse mineure déclare ses parents. La joueuse
 * majeure (18+) peut révoquer un lien à tout moment.
 */
class PirbMesParentsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParentJoueurRepository $parentRepo,
        private readonly UserRepository $userRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste des parents/référents déclarés par la joueuse + bouton "Déclarer un parent".
     * GET pirb.mabb.fr/mes-parents
     */
    #[Route('/mes-parents', name: 'pirb_mes_parents', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée à ton compte.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Liens où le joueur est concerné (peut être active, pending ou rejected)
        $liens = $this->parentRepo->createQueryBuilder('pj')
            ->where('pj.joueur = :j')
            ->setParameter('j', $joueur)
            ->orderBy('pj.statut', 'ASC')
            ->getQuery()
            ->getResult();

        $actifs = array_filter($liens, fn(ParentJoueur $p) => $p->isActive());
        $pending = array_filter($liens, fn(ParentJoueur $p) => $p->isPending());

        return $this->render('pirb/mes_parents.html.twig', [
            'joueur'  => $joueur,
            'actifs'  => $actifs,
            'pending' => $pending,
        ]);
    }

    /**
     * Recherche + déclaration d'un parent existant dans le club.
     * GET/POST pirb.mabb.fr/mes-parents/declarer
     */
    #[Route('/mes-parents/declarer', name: 'pirb_mes_parents_declarer', methods: ['GET', 'POST'])]
    public function declarer(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_declarer_parent', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('pirb_mes_parents');
            }

            $userParentId = (int) $request->request->get('user_parent_id', 0);
            $parentUser = $this->userRepo->find($userParentId);
            // [SÉCU 26/07] Le parent déclaré doit être membre du club de la
            // joueuse. Avant, n'importe quel user_id de la plateforme passait :
            // une joueuse pouvait déclarer un inconnu (ou un dirigeant d'un
            // autre club) comme son parent, et le staff validait de bonne foi.
            if ($parentUser === null || !$this->estMembreDuClub($parentUser, $joueur)) {
                $this->addFlash('error', 'Parent introuvable.');
                return $this->redirectToRoute('pirb_mes_parents');
            }

            // Anti-doublon
            $existant = $this->parentRepo->findOneBy(['parentUser' => $parentUser, 'joueur' => $joueur]);
            if ($existant !== null) {
                if ($existant->isActive()) {
                    $this->addFlash('info', sprintf('%s est déjà ton parent dans le système.', $parentUser->getPrenom()));
                } elseif ($existant->isPending()) {
                    $this->addFlash('info', sprintf('Ta déclaration pour %s est déjà en attente.', $parentUser->getPrenom()));
                } else {
                    $this->addFlash('warning', 'Une précédente déclaration a été refusée. Contacte le staff.');
                }
                return $this->redirectToRoute('pirb_mes_parents');
            }

            $pj = new ParentJoueur();
            $pj->setParentUser($parentUser);
            $pj->setJoueur($joueur);
            $pj->setStatut(ParentJoueur::STATUT_PENDING);
            $pj->setDemandePar(ParentJoueur::DEMANDE_PAR_JOUEUR);
            $this->em->persist($pj);
            $this->em->flush();

            $this->logger->info('Déclaration parent par joueuse', [
                'parent_user_id' => $parentUser->getId(),
                'joueur_id'      => $joueur->getId(),
            ]);

            $this->addFlash('success', sprintf(
                'Déclaration envoyée pour %s. Un membre du staff doit valider.',
                $parentUser->getPrenom() . ' ' . $parentUser->getNom()
            ));
            return $this->redirectToRoute('pirb_mes_parents');
        }

        // GET — recherche
        // [SÉCU 26/07] AVANT : requête sans jointure club (le docblock disait
        // pourtant « dans le club »). Une joueuse tapait « ?q=@ » et récupérait
        // les e-mails de toute la plateforme : base de phishing prête à l'emploi.
        // MAINTENANT : membres ACTIFS du club de la joueuse uniquement, 2
        // caractères minimum, et la recherche par e-mail est retirée (on ne
        // cherche pas quelqu'un dont on ne connaît pas déjà le nom).
        $recherche = trim((string) $request->query->get('q', ''));
        $candidats = [];
        $clubId = $joueur->getClub()?->getId();

        if (mb_strlen($recherche) >= 2 && $clubId !== null) {
            $r = '%' . strtolower($recherche) . '%';
            $candidats = $this->userRepo->createQueryBuilder('u')
                ->join('u.userClubRoles', 'ucr')
                ->where('LOWER(u.nom) LIKE :r OR LOWER(u.prenom) LIKE :r')
                ->andWhere('u.isActive = true')
                ->andWhere('ucr.club = :club')
                ->andWhere('ucr.status = :actif')
                ->andWhere('ucr.isActive = true')  // même exigence qu'estMembreDuClub()
                ->setParameter('r', $r)
                ->setParameter('club', $clubId)
                ->setParameter('actif', \App\Entity\Core\UserClubRole::STATUS_ACTIVE)
                ->setMaxResults(20)
                ->orderBy('u.nom', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('pirb/mes_parents_declarer.html.twig', [
            'recherche' => $recherche,
            'candidats' => $candidats,
        ]);
    }

    /**
     * [SÉCU 26/07] Vrai si $candidat est membre ACTIF du club de la joueuse.
     * Garde-fou du rattachement parental : on ne déclare pas « parent »
     * quelqu'un d'extérieur au club.
     */
    private function estMembreDuClub(User $candidat, \App\Entity\Sport\Joueur $joueur): bool
    {
        $clubId = $joueur->getClub()?->getId();
        if ($clubId === null) {
            return false;
        }
        foreach ($candidat->getUserClubRoles() as $ucr) {
            if ($ucr->getClub()?->getId() === $clubId && $ucr->isActive() && $ucr->isStatusActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Révoquer un lien parent. Réservé aux joueuses majeures (18+).
     * POST pirb.mabb.fr/mes-parents/{pjId}/revoquer
     */
    #[Route('/mes-parents/{pjId}/revoquer', name: 'pirb_mes_parents_revoquer', methods: ['POST'], requirements: ['pjId' => '\d+'])]
    public function revoquer(Request $request, int $pjId): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Vérif majeure
        $estMajeure = false;
        if ($joueur->getDateNaissance() !== null) {
            $age = $joueur->getDateNaissance()->diff(new \DateTimeImmutable())->y;
            $estMajeure = $age >= 18;
        }

        if (!$estMajeure) {
            $this->addFlash('warning', 'Tu dois être majeure (18+) pour révoquer un lien parent. Contacte le staff sinon.');
            return $this->redirectToRoute('pirb_mes_parents');
        }

        $pj = $this->parentRepo->find($pjId);
        if ($pj === null || $pj->getJoueur()?->getId() !== $joueur->getId()) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('revoquer_pj_' . $pjId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('pirb_mes_parents');
        }

        $this->em->remove($pj);
        $this->em->flush();

        $this->logger->info('Révocation lien parent par joueuse majeure', [
            'pj_id'    => $pjId,
            'joueur_id' => $joueur->getId(),
        ]);

        $this->addFlash('success', 'Lien révoqué.');
        return $this->redirectToRoute('pirb_mes_parents');
    }
}
