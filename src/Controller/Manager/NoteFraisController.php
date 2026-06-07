<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\NoteFrais;
use App\Repository\Sport\NoteFraisRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\NoteFraisVoter;
use App\Service\JustificatifNoteFraisUploader;
use App\Service\NoteFraisValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller des notes de frais — Bureau Phase D.2.
 *
 * Deux espaces distincts :
 *   - /mes-notes-frais        → l'user voit / dépose ses propres notes
 *   - /tresorerie/notes-frais → le trésorier voit la file à valider
 *
 * SÉCURITÉ : tous les accès passent par NoteFraisVoter — pas de check
 * manuel "in_array role".
 */
class NoteFraisController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly NoteFraisRepository $noteFraisRepository,
        private readonly EntityManagerInterface $em,
        private readonly JustificatifNoteFraisUploader $uploader,
        private readonly NoteFraisValidator $validator,
        private readonly LoggerInterface $logger,
    ) {}

    // ====================================================================
    // ESPACE PERSONNEL — "Mes notes de frais"
    // ====================================================================

    /**
     * Liste des notes de frais déposées par l'user connecté.
     */
    #[Route('/mes-notes-frais', name: 'manager_mes_notes_frais', methods: ['GET'])]
    public function mesNotes(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        // Tout membre actif du club peut accéder à son espace
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_SUBMIT, $club);

        return $this->render('manager/notes_frais/mes_notes.html.twig', [
            'club'  => $club,
            'notes' => $this->noteFraisRepository->findByDemandeur($user, $club),
        ]);
    }

    /**
     * Formulaire de dépôt d'une nouvelle note de frais.
     */
    #[Route('/mes-notes-frais/nouvelle', name: 'manager_note_frais_new', methods: ['GET', 'POST'])]
    public function newNoteFrais(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_SUBMIT, $club);

        $note = new NoteFrais();
        $note->setClub($club);
        $note->setDemandeur($user);
        $note->setDateDepense(new \DateTimeImmutable());

        $errors = [];

        if ($request->isMethod('POST')) {
            $errors = $this->bindAndValidate($request, $note);

            /** @var UploadedFile|null $justificatif */
            $justificatif = $request->files->get('justificatif');
            if ($justificatif === null) {
                $errors[] = 'Le justificatif est obligatoire (photo du ticket, PDF facture…).';
            }

            if (empty($errors)) {
                try {
                    // Persist sans flush pour avoir l'objet en EM, puis upload
                    $this->em->persist($note);
                    $this->em->flush(); // génère ID + permet à l'uploader d'avoir le club
                    $this->uploader->upload($justificatif, $note);
                    $this->em->flush();

                    $this->addFlash('success', 'Note de frais déposée. Le trésorier sera notifié.');
                    return $this->redirectToRoute('manager_mes_notes_frais');
                } catch (\Exception $e) {
                    // Si l'upload plante après persist, on supprime la note
                    if ($note->getId()) {
                        $this->em->remove($note);
                        $this->em->flush();
                    }
                    $this->logger->error('Échec dépôt note de frais', ['error' => $e->getMessage()]);
                    $errors[] = 'Justificatif refusé : ' . $e->getMessage();
                }
            }
        }

        return $this->render('manager/notes_frais/form.html.twig', [
            'club'   => $club,
            'note'   => $note,
            'errors' => $errors,
        ]);
    }

    /**
     * Détail d'une note de frais.
     * Visible par le demandeur OU le trésorier OU le super-admin.
     */
    #[Route('/notes-frais/{id}', name: 'manager_note_frais_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function showNoteFrais(int $id): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $note = $this->noteFraisRepository->find($id);
        if (!$note || $note->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Note de frais introuvable.');
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_VIEW, $note);

        return $this->render('manager/notes_frais/show.html.twig', [
            'club' => $club,
            'note' => $note,
        ]);
    }

    /**
     * Suppression d'une note EN_ATTENTE par son demandeur.
     * Une note validée/rejetée ne peut PAS être supprimée (verrouillage compta).
     */
    #[Route('/notes-frais/{id}/supprimer', name: 'manager_note_frais_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteNoteFrais(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $note = $this->noteFraisRepository->find($id);
        if (!$note || $note->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Note de frais introuvable.');
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_DELETE, $note);

        if (!$this->isCsrfTokenValid('delete_note_frais_' . $note->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->uploader->delete($note);
        $this->em->remove($note);
        $this->em->flush();

        $this->addFlash('success', 'Note de frais supprimée.');
        return $this->redirectToRoute('manager_mes_notes_frais');
    }

    // ====================================================================
    // ESPACE TRÉSORIER — validation
    // ====================================================================

    /**
     * File d'attente pour le trésorier : notes à valider + historique récent.
     */
    #[Route('/tresorerie/notes-frais', name: 'manager_tresorerie_notes_frais', methods: ['GET'])]
    public function tresorerieNotesFrais(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        // Pour accéder à cet écran : être trésorier ou super-admin.
        // On instancie une note bidon attachée au club pour passer au voter,
        // car CAN_VALIDATE attend une NoteFrais. Plus propre : créer un attribute
        // CAN_VIEW_QUEUE → on garde simple pour D.2.
        // Solution alternative : on délègue au TresorerieVoter qui accepte un Club.
        $this->denyAccessUnlessGranted(\App\Security\Voter\TresorerieVoter::CAN_MANAGE, $club);

        return $this->render('manager/notes_frais/tresorier_index.html.twig', [
            'club'         => $club,
            'en_attente'   => $this->noteFraisRepository->findEnAttenteByClub($club),
            'historique'   => $this->noteFraisRepository->findTraiteesByClub($club, 20),
        ]);
    }

    /**
     * Validation d'une note par le trésorier.
     * Crée auto une OperationTresorerie via NoteFraisValidator (atomique).
     */
    #[Route('/notes-frais/{id}/valider', name: 'manager_note_frais_valider', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function validerNoteFrais(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $note = $this->noteFraisRepository->find($id);
        if (!$note || $note->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Note de frais introuvable.');
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_VALIDATE, $note);

        if (!$this->isCsrfTokenValid('valider_note_frais_' . $note->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $validateur = $this->getUser();
        if (!$validateur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->validator->valider($note, $validateur);
            $this->addFlash('success', sprintf(
                'Note « %s » validée — opération de remboursement créée (%s €).',
                $note->getLibelle(),
                $note->getMontant()
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Validation échouée : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_tresorerie_notes_frais');
    }

    /**
     * Rejet d'une note par le trésorier. Motif obligatoire.
     */
    #[Route('/notes-frais/{id}/rejeter', name: 'manager_note_frais_rejeter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rejeterNoteFrais(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $note = $this->noteFraisRepository->find($id);
        if (!$note || $note->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Note de frais introuvable.');
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_VALIDATE, $note);

        if (!$this->isCsrfTokenValid('rejeter_note_frais_' . $note->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $validateur = $this->getUser();
        if (!$validateur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $motif = trim((string) $request->request->get('motif', ''));

        try {
            $this->validator->rejeter($note, $validateur, $motif);
            $this->addFlash('success', sprintf('Note « %s » rejetée.', $note->getLibelle()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Rejet échoué : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_tresorerie_notes_frais');
    }

    /**
     * Sert le justificatif d'une note (PDF/photo).
     * Accessible au demandeur + trésorier + super-admin.
     */
    #[Route('/notes-frais/{id}/justificatif', name: 'manager_note_frais_justificatif', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serveJustificatif(int $id): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $note = $this->noteFraisRepository->find($id);
        if (!$note || $note->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Note de frais introuvable.');
        }
        $this->denyAccessUnlessGranted(NoteFraisVoter::CAN_VIEW, $note);

        $path = $this->uploader->getAbsolutePath($note);
        if ($path === null) {
            throw $this->createNotFoundException('Justificatif introuvable.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $note->getJustificatifNomOriginal()
        );
        $response->headers->set('Content-Type', $note->getJustificatifMimeType());
        return $response;
    }

    // ====================================================================
    // PRIVÉ — Binding form
    // ====================================================================

    /**
     * @return string[]
     */
    private function bindAndValidate(Request $request, NoteFrais $note): array
    {
        $errors = [];

        // Montant (positif, format ##.##)
        $montantRaw = trim((string) $request->request->get('montant', ''));
        $montantRaw = str_replace(',', '.', $montantRaw);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $montantRaw)) {
            $errors[] = 'Montant invalide. Format attendu : 12.34';
        } elseif ((float) $montantRaw <= 0) {
            $errors[] = 'Le montant doit être strictement positif.';
        } else {
            $note->setMontant(number_format((float) $montantRaw, 2, '.', ''));
        }

        // Date de dépense
        $dateRaw = (string) $request->request->get('date_depense', '');
        try {
            $date = new \DateTimeImmutable($dateRaw);
            // Une date future = suspect (remboursement avant achat)
            if ($date > new \DateTimeImmutable('today 23:59:59')) {
                $errors[] = 'La date de dépense ne peut pas être dans le futur.';
            } else {
                $note->setDateDepense($date);
            }
        } catch (\Exception) {
            $errors[] = 'Date invalide.';
        }

        // Libellé
        $libelle = trim((string) $request->request->get('libelle', ''));
        if ($libelle === '') {
            $errors[] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($libelle) > 255) {
            $errors[] = 'Libellé trop long (255 caractères max).';
        } else {
            $note->setLibelle($libelle);
        }

        // Notes (optionnelles)
        $notes = trim((string) $request->request->get('notes', ''));
        $note->setNotes($notes !== '' ? $notes : null);

        return $errors;
    }
}
