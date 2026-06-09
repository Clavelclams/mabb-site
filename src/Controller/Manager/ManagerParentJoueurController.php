<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\ParentJoueur;
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
}
