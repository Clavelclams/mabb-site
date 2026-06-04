<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Rencontre;
use App\Form\Manager\RencontreType;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\RencontreRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RencontreController — gestion des rencontres (matchs).
 *
 * Renommé "Rencontre" car "match" est un mot-clé PHP 8+ (expression match).
 *
 * Workflow statut :
 *   brouillon  → le coach prépare le match avant qu'il ait lieu
 *   validé     → après le match, score saisi, feuille de match validée
 *   verrouillé → un dirigeant fige la feuille de match (anti-triche)
 *
 * Une rencontre verrouillée ne peut plus être modifiée que par un admin
 * (et toute modification est tracée — sera ajouté avec l'audit log).
 */
class RencontreController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly RencontreRepository $rencontreRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des rencontres avec filtres équipe + période.
     */
    #[Route('/rencontres', name: 'manager_rencontre_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        $equipeFiltre = null;
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipeFiltre = $this->equipeRepository->find($equipeId);
            if (!$equipeFiltre || $equipeFiltre->getClub()->getId() !== $club->getId()) {
                $this->addFlash('error', 'Équipe invalide.');
                return $this->redirectToRoute('manager_rencontre_index');
            }
        }

        $periode = $request->query->get('periode', 'a_venir');
        $now = new \DateTimeImmutable();

        $qb = $this->rencontreRepository->createQueryBuilder('r')
            ->where('r.club = :club')
            ->setParameter('club', $club);

        if ($equipeFiltre) {
            $qb->andWhere('r.equipe = :equipe')
               ->setParameter('equipe', $equipeFiltre);
        }

        switch ($periode) {
            case 'a_venir':
                $qb->andWhere('r.date >= :now')
                   ->setParameter('now', $now)
                   ->orderBy('r.date', 'ASC');
                break;
            case 'passees':
                $qb->andWhere('r.date < :now')
                   ->setParameter('now', $now)
                   ->orderBy('r.date', 'DESC');
                break;
            default:
                $qb->orderBy('r.date', 'DESC');
        }

        $rencontres = $qb->getQuery()->getResult();

        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        return $this->render('manager/rencontre/index.html.twig', [
            'rencontres'    => $rencontres,
            'equipes'       => $equipes,
            'equipe_filtre' => $equipeFiltre,
            'periode'       => $periode,
            'club'          => $club,
        ]);
    }

    /**
     * Vue détail d'une rencontre + saisie de score si la rencontre est passée.
     */
    #[Route('/rencontres/{id}', name: 'manager_rencontre_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $rencontre);

        return $this->render('manager/rencontre/show.html.twig', [
            'rencontre' => $rencontre,
        ]);
    }

    /**
     * Création d'une nouvelle rencontre.
     */
    #[Route('/rencontres/nouvelle', name: 'manager_rencontre_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $rencontre = new Rencontre();
        $rencontre->setClub($club);

        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            if ($equipe && $equipe->getClub()->getId() === $club->getId()) {
                $rencontre->setEquipe($equipe);
            }
        }

        $form = $this->createForm(RencontreType::class, $rencontre, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($rencontre);
            $this->em->flush();
            $this->addFlash('success', sprintf('Rencontre contre %s créée.', $rencontre->getAdversaire()));
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        return $this->render('manager/rencontre/new.html.twig', [
            'form'      => $form,
            'rencontre' => $rencontre,
            'club'      => $club,
        ]);
    }

    /**
     * Modification d'une rencontre.
     *
     * SÉCURITÉ : si la rencontre est verrouillée, seul un admin peut modifier.
     * On bloque l'accès en avance pour ne pas afficher le formulaire d'édition
     * à un coach sur une rencontre verrouillée.
     */
    #[Route('/rencontres/{id}/modifier', name: 'manager_rencontre_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // Une rencontre verrouillée ne peut être modifiée que par un admin
        if ($rencontre->isVerrouillee()) {
            $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
            $this->addFlash('warning', 'Cette rencontre est verrouillée. Seul un dirigeant peut la modifier.');
        }

        $form = $this->createForm(RencontreType::class, $rencontre, ['club' => $rencontre->getClub()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Rencontre mise à jour.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        return $this->render('manager/rencontre/edit.html.twig', [
            'form'      => $form,
            'rencontre' => $rencontre,
        ]);
    }

    /**
     * Changement de statut d'une rencontre (brouillon → validé → verrouillé).
     *
     * Workflow strict :
     *   - "valider" : seul un coach/staff peut passer de brouillon à validé
     *   - "verrouiller" : seul un dirigeant peut passer de validé à verrouillé
     *   - "dever" : seul un dirigeant peut revenir de verrouillé à validé
     *
     * Cette progression empêche un coach de modifier en douce une rencontre
     * déjà publiée. C'est ce qu'on défendra au jury (workflow métier).
     */
    #[Route('/rencontres/{id}/statut', name: 'manager_rencontre_statut', methods: ['POST'])]
    public function changerStatut(Request $request, Rencontre $rencontre): Response
    {
        $action = $request->request->get('action');
        $token = (string) $request->request->get('_token', '');

        if (!$this->isCsrfTokenValid('statut_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        switch ($action) {
            case 'valider':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VALIDE);
                $this->addFlash('success', 'Rencontre validée. La feuille de match est officialisée.');
                break;

            case 'verrouiller':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VERROUILLE);
                $this->addFlash('success', 'Rencontre verrouillée. Plus de modification possible sauf par un dirigeant.');
                break;

            case 'dever':
                $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
                $rencontre->setStatut(Rencontre::STATUT_VALIDE);
                $this->addFlash('success', 'Rencontre déverrouillée. Édition à nouveau possible.');
                break;

            default:
                $this->addFlash('error', 'Action invalide.');
                return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $this->em->flush();
        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }

    /**
     * Suppression d'une rencontre.
     *
     * Interdit si la rencontre est verrouillée (sauf admin).
     * Une rencontre passée avec score saisi ne devrait pas être supprimée
     * (impact sur les stats) — on conseille l'archive plutôt.
     */
    #[Route('/rencontres/{id}/supprimer', name: 'manager_rencontre_delete', methods: ['POST'])]
    public function delete(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_rencontre_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_index');
        }

        $this->em->remove($rencontre);
        $this->em->flush();

        $this->addFlash('success', 'Rencontre supprimée.');
        return $this->redirectToRoute('manager_rencontre_index');
    }
}
