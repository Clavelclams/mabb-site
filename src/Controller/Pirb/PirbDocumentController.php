<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Document;
use App\Repository\Sport\DocumentRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use App\Service\DocumentUploader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbDocumentController — ENT côté PIRB (joueurs + parents).
 *
 * Lecture seule. Les documents affichés dépendent du profil :
 *   - Joueur         → voit les docs VIS_MEMBRES du club
 *   - Parent (enfant actif) → voit les docs VIS_MEMBRES + VIS_PARENTS du club
 *
 * Routes :
 *   GET /documents          → liste des documents accessibles
 *   GET /documents/{id}/voir → serve fichier (BinaryFileResponse)
 */
class PirbDocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository    $documentRepo,
        private readonly JoueurRepository      $joueurRepo,
        private readonly ParentJoueurRepository $parentRepo,
    ) {}

    // ====================================================================
    // INDEX — Liste documents visibles par l'utilisateur PIRB
    // ====================================================================

    #[Route('/documents', name: 'pirb_documents', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Déterminer le club + si l'utilisateur est parent
        [$club, $isParent] = $this->resolveClubEtProfil($user);

        if ($club === null) {
            $this->addFlash('warning', 'Aucun club associé à ton compte. Contacte ton club.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Les parents voient MEMBRES + PARENTS ; les joueurs voient MEMBRES uniquement
        if ($isParent) {
            $documents = $this->documentRepo->findVisiblePirb($club);
        } else {
            $documents = $this->documentRepo->findVisibleMembres($club);
        }

        // Grouper par type pour l'affichage
        $documentsByType = [];
        foreach ($documents as $doc) {
            $documentsByType[$doc->getType()][] = $doc;
        }

        return $this->render('pirb/document/index.html.twig', [
            'documents_by_type' => $documentsByType,
            'types'             => Document::TYPE_LIBELLES,
            'total'             => count($documents),
            'is_parent'         => $isParent,
            'club'              => $club,
        ]);
    }

    // ====================================================================
    // VOIR — Serve le fichier (sécurisé)
    // ====================================================================

    #[Route('/documents/{id}/voir', name: 'pirb_document_voir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function voir(Document $document, DocumentUploader $uploader): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        [$club, $isParent] = $this->resolveClubEtProfil($user);

        if ($club === null) {
            throw $this->createAccessDeniedException();
        }

        // Anti-idor : le document doit appartenir au même club
        if ($document->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException();
        }

        // Vérifier la visibilité
        $visibilite = $document->getVisibilite();
        if ($visibilite === Document::VIS_STAFF) {
            // Les joueurs/parents ne peuvent jamais voir les docs STAFF
            throw $this->createAccessDeniedException('Ce document est réservé au staff.');
        }
        if ($visibilite === Document::VIS_PARENTS && !$isParent) {
            // Les docs PARENTS ne sont pas visibles aux joueurs non-parents
            throw $this->createAccessDeniedException('Ce document est réservé aux parents.');
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
    // HELPER — Résoudre le club + profil de l'utilisateur PIRB
    // ====================================================================

    /**
     * Renvoie [club, isParent] pour l'utilisateur connecté.
     *
     * Logique :
     *   1. L'utilisateur a un Joueur lié → club du joueur, isParent = false
     *   2. L'utilisateur est parent d'au moins un enfant actif → club de l'enfant, isParent = true
     *   3. Sinon → [null, false]
     */
    private function resolveClubEtProfil(User $user): array
    {
        // Cas 1 : joueur
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur !== null && $joueur->getClub() !== null) {
            return [$joueur->getClub(), false];
        }

        // Cas 2 : parent d'un enfant actif
        $liensActifs = $this->parentRepo->createQueryBuilder('pj')
            ->join('pj.joueur', 'j')
            ->where('pj.parentUser = :u')
            ->andWhere('pj.statut = :actif')
            ->setParameter('u', $user)
            ->setParameter('actif', 'ACTIVE')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (!empty($liensActifs)) {
            $clubEnfant = $liensActifs[0]->getJoueur()?->getClub();
            if ($clubEnfant !== null) {
                return [$clubEnfant, true];
            }
        }

        return [null, false];
    }
}
