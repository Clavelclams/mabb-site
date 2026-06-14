<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\ParentJoueur;
use App\Repository\Core\UserRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerParentJoueurController — validation des liens parent-enfant.
 *
 * Accessible CLUB_STAFF. Voit les demandes pending pour les joueurs du
 * club et peut valider/refuser.
 *
 * PIRB V1.4d.
 */
class ManagerParentJoueurController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly ParentJoueurRepository $parentRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste des demandes de lien parent-enfant en attente.
     * GET manager.mabb.fr/parents-enfants
     */
    #[Route('/parents-enfants', name: 'manager_parents_enfants_index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $demandes = $this->parentRepo->findDemandesEnAttenteParClub($club);

        // Aussi les liens actifs pour affichage historique
        $actifs = $this->parentRepo->createQueryBuilder('pj')
            ->join('pj.joueur', 'j')
            ->where('j.club = :c')
            ->andWhere('pj.statut = :a')
            ->setParameter('c', $club)
            ->setParameter('a', ParentJoueur::STATUT_ACTIVE)
            ->orderBy('pj.valideAt', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        return $this->render('manager/parents_enfants/index.html.twig', [
            'club'     => $club,
            'demandes' => $demandes,
            'actifs'   => $actifs,
        ]);
    }

    /**
     * Valide une demande de lien.
     * POST manager.mabb.fr/parents-enfants/{id}/valider
     */
    #[Route('/parents-enfants/{id}/valider', name: 'manager_parents_enfants_valider', methods: ['POST'])]
    public function valider(Request $request, ParentJoueur $pj): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $pj);

        if (!$this->isCsrfTokenValid('valider_pj_' . $pj->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_parents_enfants_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $pj->setStatut(ParentJoueur::STATUT_ACTIVE);
        $pj->setValidePar($user);
        $pj->setValideAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('Lien parent-enfant validé', [
            'pj_id'           => $pj->getId(),
            'parent_user_id'  => $pj->getParentUser()?->getId(),
            'joueur_id'       => $pj->getJoueur()?->getId(),
            'valide_par'      => $user->getUserIdentifier(),
        ]);

        $this->addFlash('success', sprintf(
            '✅ Lien validé : %s ↔ %s',
            $pj->getParentUser()?->getPrenom() . ' ' . $pj->getParentUser()?->getNom(),
            $pj->getJoueur()?->getPrenom() . ' ' . $pj->getJoueur()?->getNom()
        ));
        return $this->redirectToRoute('manager_parents_enfants_index');
    }

    /**
     * Refuse une demande de lien.
     * POST manager.mabb.fr/parents-enfants/{id}/refuser
     */
    #[Route('/parents-enfants/{id}/refuser', name: 'manager_parents_enfants_refuser', methods: ['POST'])]
    public function refuser(Request $request, ParentJoueur $pj): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $pj);

        if (!$this->isCsrfTokenValid('refuser_pj_' . $pj->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_parents_enfants_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        $pj->setStatut(ParentJoueur::STATUT_REJECTED);
        $pj->setValidePar($user);
        $pj->setValideAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('Lien parent-enfant refusé', [
            'pj_id'           => $pj->getId(),
            'parent_user_id'  => $pj->getParentUser()?->getId(),
            'joueur_id'       => $pj->getJoueur()?->getId(),
            'refuse_par'      => $user->getUserIdentifier(),
        ]);

        $this->addFlash('warning', 'Demande refusée. Le parent peut renvoyer une demande plus tard.');
        return $this->redirectToRoute('manager_parents_enfants_index');
    }

    /**
     * [B30a 12/06/2026] Staff lie un parent existant à une joueuse en 1 clic.
     * Différent de la validation : ici staff INITIE le lien.
     *
     * GET/POST manager.mabb.fr/joueuses/{joueurId}/lier-parent
     *   GET  → form recherche User par email/nom dans le club
     *   POST → crée ParentJoueur statut=ACTIVE directement
     */
    #[Route('/joueuses/{joueurId}/lier-parent', name: 'manager_parents_enfants_lier_staff', methods: ['GET', 'POST'], requirements: ['joueurId' => '\d+'])]
    public function lierParentStaff(Request $request, int $joueurId): Response
    {
        $joueur = $this->joueurRepo->find($joueurId);
        if ($joueur === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        $club = $joueur->getClub();
        $candidats = [];
        $recherche = trim((string) $request->query->get('q', ''));

        if ($recherche !== '') {
            // Recherche User par email ou nom (dans le club via UserClubRole)
            $r = '%' . strtolower($recherche) . '%';
            $candidats = $this->userRepo->createQueryBuilder('u')
                ->where('LOWER(u.email) LIKE :r OR LOWER(u.nom) LIKE :r OR LOWER(u.prenom) LIKE :r')
                ->setParameter('r', $r)
                ->andWhere('u.isActive = true')
                ->setMaxResults(20)
                ->orderBy('u.nom', 'ASC')
                ->getQuery()
                ->getResult();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lier_parent_staff_' . $joueur->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('manager_parents_enfants_lier_staff', ['joueurId' => $joueur->getId()]);
            }

            $userParentId = (int) $request->request->get('user_parent_id', 0);
            $parentUser = $this->userRepo->find($userParentId);
            if ($parentUser === null) {
                $this->addFlash('error', 'Parent introuvable.');
                return $this->redirectToRoute('manager_parents_enfants_lier_staff', ['joueurId' => $joueur->getId()]);
            }

            // Vérif anti-doublon
            $existant = $this->parentRepo->findOneBy(['parentUser' => $parentUser, 'joueur' => $joueur]);
            if ($existant !== null) {
                if ($existant->isActive()) {
                    $this->addFlash('info', 'Ce lien est déjà actif.');
                } elseif ($existant->isPending()) {
                    // Staff valide direct
                    $existant->setStatut(ParentJoueur::STATUT_ACTIVE);
                    $existant->setValidePar($this->getUser());
                    $existant->setValideAt(new \DateTimeImmutable());
                    $this->em->flush();
                    $this->addFlash('success', '✅ Demande pending validée par le staff.');
                } else {
                    $this->addFlash('warning', 'Ce lien a été refusé précédemment. Crée-en un nouveau si besoin.');
                }
                return $this->redirectToRoute('manager_parents_enfants_index');
            }

            $pj = new ParentJoueur();
            $pj->setParentUser($parentUser);
            $pj->setJoueur($joueur);
            $pj->setStatut(ParentJoueur::STATUT_ACTIVE);
            $pj->setDemandePar(ParentJoueur::DEMANDE_PAR_STAFF);
            $pj->setValidePar($this->getUser());
            $pj->setValideAt(new \DateTimeImmutable());
            $this->em->persist($pj);
            $this->em->flush();

            $this->logger->info('Lien parent-enfant créé par staff', [
                'pj_id'         => $pj->getId(),
                'parent_user_id' => $parentUser->getId(),
                'joueur_id'      => $joueur->getId(),
                'staff'          => $this->getUser()?->getUserIdentifier(),
            ]);

            $this->addFlash('success', sprintf(
                '✅ Lien créé : %s ↔ %s',
                $parentUser->getPrenom() . ' ' . $parentUser->getNom(),
                $joueur->getPrenom() . ' ' . $joueur->getNom()
            ));
            return $this->redirectToRoute('manager_parents_enfants_index');
        }

        return $this->render('manager/parents_enfants/lier_staff.html.twig', [
            'joueur'    => $joueur,
            'recherche' => $recherche,
            'candidats' => $candidats,
        ]);
    }
}
