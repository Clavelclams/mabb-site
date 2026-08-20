<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\Document;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\DocumentRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiDocumentController — [Natif 20/08/2026]
 *
 * Documents du club + liens parents, EN NATIF. C'étaient les DEUX dernières
 * WebViews du menu burger de l'app Venaball (avec le Playground, assumé).
 * Apple rejette les apps « habillage de site » (Guideline 4.2) : chaque
 * WebView en moins est un argument de revue en plus — et un écran natif est
 * plus rapide, plus lisible, utilisable en cache.
 *
 *   GET /api/pirb/documents   → la liste des documents visibles
 *   GET /api/pirb/mes-parents → les liens parents de MA fiche (lecture seule)
 *
 * RÈGLES DE VISIBILITÉ — les MÊMES que le web (PirbDocumentController) :
 *   - compte joueuse                 → documents VIS_MEMBRES du club
 *   - compte parent (enfant ACTIF)   → VIS_MEMBRES + VIS_PARENTS
 *   - VIS_STAFF                      → jamais par cette API
 *
 * LE FICHIER LUI-MÊME ne passe PAS par l'API Bearer : l'app ouvre
 * /documents/{id}/voir via un ticket SSO (mécanisme existant, 90 s, HMAC)
 * → le contrôle d'accès web s'applique, aucun octet ne transite par un
 * canal nouveau. On expose la liste, pas un deuxième chemin de fichiers.
 *
 * MES PARENTS : lecture seule volontaire. Ajouter/retirer un parent est un
 * geste RGPD sensible (mineures) qui reste sur le web, avec ses gardes-fous
 * (recherche bornée au club, validation). L'app AFFICHE l'état, c'est tout.
 */
class PirbApiDocumentController extends AbstractController
{
    public function __construct(
        private readonly DocumentRepository $documentRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly ParentJoueurRepository $parentRepo,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // GET /api/pirb/documents
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/documents', name: 'api_pirb_documents', methods: ['GET'])]
    public function documents(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        [$club, $estParent] = $this->clubEtProfil($user);
        if ($club === null) {
            return new JsonResponse(
                ['error' => "Aucun club associé à ce compte. Contacte ton club."],
                Response::HTTP_NOT_FOUND
            );
        }

        // Mêmes requêtes que le web : le parent voit MEMBRES + PARENTS.
        $documents = $estParent
            ? $this->documentRepo->findVisiblePirb($club)
            : $this->documentRepo->findVisibleMembres($club);

        $lignes = [];
        foreach ($documents as $doc) {
            $lignes[] = [
                'id'          => $doc->getId(),
                'nom'         => $doc->getNomOriginal(),
                'type'        => $doc->getType(),
                'typeLibelle' => Document::TYPE_LIBELLES[$doc->getType()] ?? $doc->getType(),
                'mimeType'    => $doc->getMimeType(),
                'creeLe'      => $doc->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                // Le chemin WEB à ouvrir via ticket SSO — l'app le donne tel
                // quel à ssoUrl(). Le contrôle d'accès reste côté web.
                'cheminVoir'  => '/documents/' . $doc->getId() . '/voir',
            ];
        }

        return new JsonResponse([
            'documents' => $lignes,
            'estParent' => $estParent,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // GET /api/pirb/mes-parents
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/mes-parents', name: 'api_pirb_mes_parents', methods: ['GET'])]
    public function mesParents(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        // Cette route est celle de la JOUEUSE : ses parents à ELLE.
        // (Un compte parent consulte ses enfants ailleurs — web ou app club.)
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if (!$joueur instanceof Joueur) {
            return new JsonResponse(
                ['error' => 'Aucune fiche joueuse liée à ce compte.'],
                Response::HTTP_NOT_FOUND
            );
        }

        $lignes = [];
        foreach ($this->parentRepo->findBy(['joueur' => $joueur]) as $lien) {
            $parent = $lien->getParentUser();
            if ($parent === null || $lien->isRejected()) {
                continue; // un lien rejeté n'a rien à faire à l'écran
            }
            $lignes[] = [
                'id'     => $lien->getId(),
                'prenom' => $parent->getPrenom(),
                'nom'    => $parent->getNom(),
                // 'active' = validé par le club ; 'pending' = en attente.
                'statut' => $lien->getStatut(),
            ];
        }

        return new JsonResponse(['parents' => $lignes]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Privé — le même résolveur que le web (PirbDocumentController)
    // ────────────────────────────────────────────────────────────────────

    /** @return array{0: ?\App\Entity\Core\Club, 1: bool} [club, estParent] */
    private function clubEtProfil(User $user): array
    {
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur !== null && $joueur->getClub() !== null) {
            return [$joueur->getClub(), false];
        }

        foreach ($this->parentRepo->findBy(['parentUser' => $user]) as $lien) {
            if ($lien->isActive() && $lien->getJoueur()?->getClub() !== null) {
                return [$lien->getJoueur()->getClub(), true];
            }
        }

        return [null, false];
    }
}
