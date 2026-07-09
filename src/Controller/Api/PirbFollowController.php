<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Pirb\Follow;
use App\Entity\Sport\Joueur;
use App\Repository\Pirb\FollowRepository;
use App\Repository\Sport\JoueurRepository;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbFollowController — [Social V1, 09/07/2026] le suivi entre joueuses.
 *
 * Contrat consommé par l'app (Pirb store) :
 *   GET  /api/pirb/social/counts        → SocialCounts { abonnes, abonnements }
 *   GET  /api/pirb/social/abonnes       → JoueurPublicCard[] (celles qui ME suivent)
 *   GET  /api/pirb/social/abonnements   → JoueurPublicCard[] (celles que JE suis)
 *   POST /api/pirb/follow/{id}/toggle   → { suivie: bool } (nouvel état)
 *
 * POURQUOI un toggle et pas POST/DELETE séparés : l'app fait de l'optimiste
 * (le bouton change avant la réponse réseau). Si deux taps partent en
 * parallèle, un POST « suivre » sur un lien déjà présent serait une erreur
 * à gérer. Avec le toggle, le SERVEUR tranche et renvoie l'état final —
 * l'app se resynchronise dessus, zéro cas d'erreur artificiel.
 *
 * RÈGLES V1 (RGPD public mineur — même périmètre que /commu) :
 *  - cible dans MON club uniquement, active, et pas moi-même ;
 *  - les listes ne renvoient que le minimum club (nom, équipe, poste,
 *    photo), c'est-à-dire la même carte que la commu ;
 *  - 0 abonnée / 0 abonnement = listes vides et compteurs à 0, c'est tout
 *    (décision produit Clavel 09/07 : pas d'écran bloqué, pas de fake).
 *
 * SÉCURITÉ : même modèle que PirbApiController — le user vient du Bearer
 * (firewall api), tout est dérivé de SA fiche Joueur, l'id ciblé est
 * vérifié club par club. Impossible de lire le graphe d'un autre club.
 */
class PirbFollowController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly FollowRepository $followRepo,
        private readonly SaisonService $saisonService,
        private readonly EntityManagerInterface $em,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/social/counts
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/social/counts', name: 'api_pirb_social_counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        return new JsonResponse([
            'abonnes'     => $this->followRepo->compteAbonnes($moi),
            'abonnements' => $this->followRepo->compteAbonnements($moi),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/social/abonnes — celles qui me suivent
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/social/abonnes', name: 'api_pirb_social_abonnes', methods: ['GET'])]
    public function abonnes(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        return new JsonResponse(
            $this->enCartes($this->followRepo->abonnesDe($moi), $moi, $request)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/social/abonnements — celles que je suis
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/social/abonnements', name: 'api_pirb_social_abonnements', methods: ['GET'])]
    public function abonnements(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        return new JsonResponse(
            $this->enCartes($this->followRepo->abonnementsDe($moi), $moi, $request)
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/pirb/follow/{id}/toggle
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/follow/{id}/toggle', name: 'api_pirb_follow_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        if ($moi->getId() === $id) {
            return new JsonResponse(['error' => 'Tu ne peux pas te suivre toi-même.'], Response::HTTP_BAD_REQUEST);
        }

        $cible = $this->joueurRepo->find($id);

        // V1 intra-club : hors de mon club = « n'existe pas » (404, pas 403 —
        // on ne confirme jamais l'existence d'une joueuse d'un autre club).
        if (
            !$cible instanceof Joueur
            || !$cible->isActive()
            || $cible->getClub()?->getId() !== $moi->getClub()?->getId()
        ) {
            return new JsonResponse(['error' => 'Joueuse introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $lien = $this->followRepo->trouverPaire($moi, $cible);
        if ($lien !== null) {
            // Déjà suivie → on ne suit plus.
            $this->em->remove($lien);
            $this->em->flush();
            return new JsonResponse(['suivie' => false]);
        }

        // Pas encore suivie → on suit.
        $this->em->persist(Follow::creer($moi, $cible));
        $this->em->flush();
        return new JsonResponse(['suivie' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Privé
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Joueur[] → JoueurPublicCard[] — EXACTEMENT la même carte que /commu
     * (types/pirb.ts::JoueurPublicCard). `suivie` = est-ce que MOI je la
     * suis (utile pour afficher le bon bouton dans la liste des abonnées).
     *
     * @param Joueur[] $joueuses
     */
    private function enCartes(array $joueuses, Joueur $moi, Request $request): array
    {
        $saison       = $this->saisonService->getSaisonCourante();
        $monEquipe    = $moi->equipePourSaison($saison) ?? $moi->getEquipe();
        $monEquipeId  = $monEquipe?->getId();
        $idsQueJeSuis = $this->followRepo->idsSuiviesPar($moi); // 1 requête, pas N
        $base         = $request->getSchemeAndHttpHost();

        $cartes = [];
        foreach ($joueuses as $j) {
            $equipe = $j->equipePourSaison($saison) ?? $j->getEquipe();

            $cartes[] = [
                'id'             => $j->getId(),
                'pseudo'         => trim(($j->getPrenom() ?? '') . ' ' . ($j->getNom() ?? '')),
                'photoUrl'       => $j->getPhotoPath() !== null
                    ? $base . '/' . ltrim($j->getPhotoPath(), '/')
                    : null,
                'club'           => $j->getClub()?->getNom() ?? '',
                'equipe'         => $equipe?->getNom(),
                'poste'          => $j->getPoste(),
                'suivie'         => in_array($j->getId(), $idsQueJeSuis, true),
                'estCoequipiere' => $monEquipeId !== null && $equipe?->getId() === $monEquipeId,
            ];
        }

        return $cartes;
    }

    /**
     * Fiche Joueur du user authentifié, ou 404 JSON.
     * Copie assumée de PirbApiController::joueurOu404 — 2 occurrences,
     * on factorisera en trait au 3e usage (règle des trois).
     */
    private function joueurOu404(): Joueur|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            return new JsonResponse(
                ['error' => 'Aucune fiche joueuse liée à ce compte. Contacte le staff du club.'],
                Response::HTTP_NOT_FOUND
            );
        }
        return $joueur;
    }
}
