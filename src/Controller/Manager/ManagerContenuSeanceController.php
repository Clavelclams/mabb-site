<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\ContenuSeance;
use App\Form\Manager\ContenuSeanceType;
use App\Repository\Sport\ContenuSeanceRepository;
use App\Repository\Sport\ThemeSeanceRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\ContenuSeanceMediaUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bibliothèque de fiches séances (ContenuSeance) — accessible depuis Manager.
 *
 * URL de base : /seances/
 *
 * Qui peut faire quoi :
 *   - CLUB_COACH  : créer + voir ses propres fiches + fiches publiques club
 *   - CLUB_STAFF  : idem + voir tout (y compris fiches privées d'autres coachs)
 *   - CLUB_ADMIN  : idem STAFF
 *
 * Multi-tenant : toutes les requêtes sont filtrées par club résolu via TenantResolver.
 */
#[Route('/seances', name: 'manager_seances_')]
class ManagerContenuSeanceController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
        private readonly ContenuSeanceRepository $contenuRepo,
        private readonly ThemeSeanceRepository $themeRepo,
        private readonly ContenuSeanceMediaUploader $uploader,
    ) {}

    /**
     * Liste (bibliothèque) — index principal.
     * Le coach voit ses fiches + fiches publiques club.
     * Staff/Admin voit tout.
     *
     * GET /seances/
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        /** @var \App\Entity\Core\User $user */
        $user = $this->getUser();

        // Filtres optionnels via query string
        $filtreCategorie = $request->query->get('categorie');
        $filtreThemeId   = $request->query->getInt('theme') ?: null;

        if ($filtreCategorie || $filtreThemeId) {
            $contenus = $this->contenuRepo->findBibliothequeFiltree(
                $club,
                $user,
                $filtreCategorie ?: null,
                $filtreThemeId
            );
        } else {
            $contenus = $this->contenuRepo->findBibliotheque($club, $user);
        }

        $themes = $this->themeRepo->findParGroupePourClub($club);

        return $this->render('manager/contenu_seance/index.html.twig', [
            'club'             => $club,
            'contenus'         => $contenus,
            'themes'           => $themes,
            'filtreCategorie'  => $filtreCategorie,
            'filtreThemeId'    => $filtreThemeId,
            'categoriesAge'    => ContenuSeance::CATEGORIES_AGE,
        ]);
    }

    /**
     * Création d'une nouvelle fiche séance.
     *
     * GET  /seances/nouvelle
     * POST /seances/nouvelle
     */
    #[Route('/nouvelle', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        /** @var \App\Entity\Core\User $user */
        $user = $this->getUser();

        $contenu = new ContenuSeance();
        $contenu->setClub($club);
        $contenu->setCreatedBy($user);

        $form = $this->createForm(ContenuSeanceType::class, $contenu, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Upload des fichiers (non mappés, gérés manuellement)
            $files = $form->get('fichiers_upload')->getData();
            if (!empty($files)) {
                // Persister d'abord pour obtenir l'ID (club_id suffit pour le dossier)
                $this->em->persist($contenu);
                $this->em->flush(); // flush sans les fichiers pour avoir l'ID si besoin

                $nbFichiers = $this->uploader->uploadPourContenu(array_slice($files, 0, 5), $contenu);
                if ($nbFichiers > 0) {
                    $this->em->flush(); // flush avec les fichiers mis à jour
                }
            } else {
                $this->em->persist($contenu);
                $this->em->flush();
            }

            $this->addFlash('success', sprintf('Fiche "%s" créée avec succès.', $contenu->getTitre()));
            return $this->redirectToRoute('manager_seances_show', ['id' => $contenu->getId()]);
        }

        return $this->render('manager/contenu_seance/new.html.twig', [
            'club' => $club,
            'form' => $form,
        ]);
    }

    /**
     * Détail d'une fiche séance.
     *
     * GET /seances/{id}
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ContenuSeance $contenu): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);

        $this->checkAccesFiche($contenu, $club);

        return $this->render('manager/contenu_seance/show.html.twig', [
            'club'    => $club,
            'contenu' => $contenu,
        ]);
    }

    /**
     * Modification d'une fiche séance.
     *
     * GET  /seances/{id}/modifier
     * POST /seances/{id}/modifier
     */
    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ContenuSeance $contenu): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);
        $this->checkAccesFiche($contenu, $club);

        // Seul le créateur ou un admin/staff peut modifier
        /** @var \App\Entity\Core\User $user */
        $user = $this->getUser();
        $peutModifier = $contenu->getCreatedBy() === $user
            || $this->isGranted(ClubVoter::CLUB_STAFF, $club);

        if (!$peutModifier) {
            $this->addFlash('error', 'Tu ne peux modifier que tes propres fiches.');
            return $this->redirectToRoute('manager_seances_show', ['id' => $contenu->getId()]);
        }

        $form = $this->createForm(ContenuSeanceType::class, $contenu, ['club' => $club]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $files = $form->get('fichiers_upload')->getData();
            if (!empty($files)) {
                // Limite globale à 5 fichiers
                $dejaFichiers = count($contenu->getFichiers());
                $slots = max(0, 5 - $dejaFichiers);
                if ($slots > 0) {
                    $this->uploader->uploadPourContenu(array_slice($files, 0, $slots), $contenu);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Fiche mise à jour.');
            return $this->redirectToRoute('manager_seances_show', ['id' => $contenu->getId()]);
        }

        return $this->render('manager/contenu_seance/edit.html.twig', [
            'club'    => $club,
            'form'    => $form,
            'contenu' => $contenu,
        ]);
    }

    /**
     * Suppression d'un fichier joint d'une fiche.
     *
     * POST /seances/{id}/fichiers/{index}/supprimer
     */
    #[Route('/{id}/fichiers/{index}/supprimer', name: 'delete_fichier', methods: ['POST'], requirements: ['id' => '\d+', 'index' => '\d+'])]
    public function deleteFichier(Request $request, ContenuSeance $contenu, int $index): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_COACH, $club);
        $this->checkAccesFiche($contenu, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_fichier_' . $contenu->getId() . '_' . $index, $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_seances_show', ['id' => $contenu->getId()]);
        }

        $fichiers = $contenu->getFichiers();
        if (isset($fichiers[$index])) {
            $this->uploader->supprimerFichier($fichiers[$index]);
            $contenu->removeFichierByIndex($index);
            $this->em->flush();
            $this->addFlash('success', 'Fichier supprimé.');
        }

        return $this->redirectToRoute('manager_seances_show', ['id' => $contenu->getId()]);
    }

    /**
     * Suppression complète d'une fiche séance.
     *
     * POST /seances/{id}/supprimer
     */
    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ContenuSeance $contenu): Response
    {
        $club = $this->resolveClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);
        $this->checkAccesFiche($contenu, $club);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_contenu_' . $contenu->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_seances_index');
        }

        // Suppression physique de tous les fichiers AVANT de supprimer l'entité
        $this->uploader->supprimerTousFichiers($contenu);

        $this->em->remove($contenu);
        $this->em->flush();

        $this->addFlash('success', 'Fiche séance supprimée.');
        return $this->redirectToRoute('manager_seances_index');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Résout le Club courant via TenantResolver (session + hôte HTTP).
     */
    private function resolveClub(): \App\Entity\Core\Club
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createAccessDeniedException('Aucun club actif.');
        }
        return $club;
    }

    /**
     * Vérifie que la fiche appartient bien au club courant (multi-tenant guard).
     */
    private function checkAccesFiche(ContenuSeance $contenu, \App\Entity\Core\Club $club): void
    {
        if ($contenu->getClub()->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException('Cette fiche appartient à un autre club.');
        }
    }
}
