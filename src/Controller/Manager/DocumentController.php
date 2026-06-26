<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\Document;
use App\Repository\Sport\DocumentRepository;
use App\Security\ClubVoter;
use App\Service\DocumentUploader;
use App\Security\Tenant\TenantResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DocumentController — ENT (Espace Numérique de Travail) côté Manager.
 *
 * Accès :
 *   - LECTURE  : CLUB_MEMBER (tous les membres actifs du club)
 *   - UPLOAD   : CLUB_STAFF_ELARGI (coach, dirigeant, staff, trésorier, employé)
 *   - DELETE   : CLUB_STAFF_ELARGI (seul l'uploader ou le staff élargi)
 *
 * Les documents sont filtrés par VISIBILITÉ :
 *   - VIS_STAFF   → affichés uniquement au staff élargi
 *   - VIS_MEMBRES → affichés à tous les membres
 *   - VIS_PARENTS → affichés côté PIRB aux parents (pas dans Manager côté membre)
 *
 * Routes (host: manager.{domain}) :
 *   GET  /ent                    → index + liste + formulaire upload
 *   POST /ent/upload             → traitement upload
 *   GET  /ent/{id}/voir          → serve fichier (BinaryFileResponse)
 *   POST /ent/{id}/supprimer     → suppression document
 */
class DocumentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantResolver         $tenantResolver,
    ) {}

    // ====================================================================
    // INDEX — Liste + formulaire upload
    // ====================================================================

    #[Route('/ent', name: 'manager_ent_index', methods: ['GET'])]
    public function index(DocumentRepository $repo, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Filtre par type (optionnel)
        $filtreType = $request->query->get('type');

        if ($filtreType && in_array($filtreType, Document::TYPES, true)) {
            $documents = $repo->findByClubAndType($club, $filtreType);
        } else {
            $documents = $repo->findByClub($club);
            $filtreType = null;
        }

        // Pour l'affichage : séparer ce que le membre courant peut voir
        // Un CLUB_STAFF_ELARGI voit tout ; un membre simple ne voit pas VIS_STAFF
        $isStaffElargi = $this->isGranted(ClubVoter::CLUB_STAFF_ELARGI, $club);

        if (!$isStaffElargi) {
            $documents = array_filter(
                $documents,
                fn(Document $d) => $d->getVisibilite() !== Document::VIS_STAFF
            );
            $documents = array_values($documents);
        }

        return $this->render('manager/document/index.html.twig', [
            'documents'     => $documents,
            'types'         => Document::TYPE_LIBELLES,
            'visibilites'   => Document::VISIBILITE_LIBELLES,
            'filtre_type'   => $filtreType,
            'is_staff'      => $isStaffElargi,
            'club'          => $club,
        ]);
    }

    // ====================================================================
    // UPLOAD
    // ====================================================================

    #[Route('/ent/upload', name: 'manager_ent_upload', methods: ['POST'])]
    public function upload(Request $request, DocumentUploader $uploader): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF_ELARGI, $club);

        // CSRF
        if (!$this->isCsrfTokenValid('ent_upload_' . $club->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide. Réessaie.');
            return $this->redirectToRoute('manager_ent_index');
        }

        $file = $request->files->get('fichier');
        if (!$file) {
            $this->addFlash('error', 'Aucun fichier sélectionné.');
            return $this->redirectToRoute('manager_ent_index');
        }

        $type      = $request->request->get('type', Document::TYPE_AUTRE);
        $titre     = trim($request->request->get('titre', ''));
        $visibilite = $request->request->get('visibilite', '');
        $description = trim($request->request->get('description', '')) ?: null;

        // Validation type
        if (!in_array($type, Document::TYPES, true)) {
            $this->addFlash('error', 'Type de document invalide.');
            return $this->redirectToRoute('manager_ent_index');
        }

        // Titre obligatoire
        if ($titre === '') {
            $this->addFlash('error', 'Le titre est obligatoire.');
            return $this->redirectToRoute('manager_ent_index');
        }

        // Visibilité : si non fournie ou invalide → prendre le défaut du type
        if (!in_array($visibilite, Document::VISIBILITES, true)) {
            $visibilite = Document::TYPE_VISIBILITE_DEFAUT[$type];
        }

        try {
            $doc = $uploader->upload($file, $club);
            $doc->setTitre($titre);
            $doc->setType($type);
            $doc->setVisibilite($visibilite);
            $doc->setDescription($description);
            $doc->setUploadePar($this->getUser());

            $this->em->persist($doc);
            $this->em->flush();

            $this->addFlash('success', sprintf('Document « %s » ajouté avec succès.', $titre));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('manager_ent_index');
    }

    // ====================================================================
    // SERVE — Téléchargement sécurisé
    // ====================================================================

    #[Route('/ent/{id}/voir', name: 'manager_ent_voir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function voir(Document $document, DocumentUploader $uploader): Response
    {
        // Vérifier que l'utilisateur a le droit de voir CE document
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Vérifier que le document appartient au bon club (anti-idor)
        if ($document->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException();
        }

        // Vérifier la visibilité
        $isStaff = $this->isGranted(ClubVoter::CLUB_STAFF_ELARGI, $club);
        if ($document->getVisibilite() === Document::VIS_STAFF && !$isStaff) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à ce document.');
        }

        $absolutePath = $uploader->getAbsolutePath($document);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Fichier introuvable sur le serveur.');
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $document->getMimeType() ?? 'application/octet-stream');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getNomOriginal() ?? 'document'
        );

        return $response;
    }

    // ====================================================================
    // SUPPRIMER
    // ====================================================================

    #[Route('/ent/{id}/supprimer', name: 'manager_ent_supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimer(Request $request, Document $document, DocumentUploader $uploader): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF_ELARGI, $club);

        // Anti-idor
        if ($document->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException();
        }

        // CSRF
        if (!$this->isCsrfTokenValid('ent_supprimer_' . $document->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_ent_index');
        }

        $titre = $document->getTitre();
        $uploader->delete($document);
        $this->em->remove($document);
        $this->em->flush();

        $this->addFlash('success', sprintf('Document « %s » supprimé.', $titre));

        return $this->redirectToRoute('manager_ent_index');
    }
}
