<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Evenement;
use App\Entity\Sport\EvenementParticipation;
use App\Repository\Sport\AffectationMatchRepository;
use App\Repository\Sport\EvenementParticipationRepository;
use App\Repository\Sport\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-9 13/08/2026] La VIE DU CLUB — la vue bénévole de l'app.
 *
 * Ce que voit un bénévole en ouvrant l'app : les événements à venir du club
 * (AG, tournois, sorties, fêtes) avec son état d'inscription, et ses
 * missions de match (chrono, e-marque, buvette…) avec leur statut.
 * C'est la vue de TOUT LE MONDE : contrairement à la vue coach, elle ne
 * demande aucun rôle particulier — être membre du club suffit, comme sur
 * le web.
 *
 *   GET  /api/club/vie → {evenements, missions, candidatures}
 *   POST /api/club/evenements/{id}/participation {action} → s'inscrire /
 *        se désinscrire en un tap
 *
 * Les RÈGLES d'inscription sont celles du web (EvenementController) :
 * événement publié, pas complet, pas de double inscription. Réécrites ici
 * car côté web elles sont mêlées aux flash/redirects — même arbitrage que
 * pour la saisie : à trois clients on extraira un service.
 */
final class ApiClubVieController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvenementRepository $evenementRepository,
        private readonly EvenementParticipationRepository $participationRepository,
        private readonly AffectationMatchRepository $affectationRepository,
    ) {}

    /**
     * GET — tout ce qu'il faut pour l'écran « vie du club ».
     */
    #[Route('/api/club/vie', name: 'api_club_vie', methods: ['GET'])]
    public function vie(Request $request): JsonResponse
    {
        $club = $this->clubCourant($request);
        // Pas d'exigerRole : la vie du club appartient à tout membre.
        // clubCourant() a déjà vérifié l'appartenance au club.
        $user = $this->utilisateur();

        // ── Les événements à venir, avec MON état d'inscription ──────────
        $evenements = [];
        foreach ($this->evenementRepository->avenirParClub($club, [Evenement::STATUT_PUBLIE], 20) as $e) {
            $participation = $this->participationRepository->trouverPour($user, $e);
            $evenements[] = [
                'id'          => $e->getId(),
                'titre'       => $e->getTitre(),
                'type'        => $e->getType(),
                'typeLibelle' => $e->getTypeLibelle(),
                'date'        => $e->getDate()?->format(\DateTimeInterface::ATOM),
                'lieu'        => $e->getLieu(),
                'ouvertA'     => $e->getOuvertALibelle(),
                'complet'     => $e->isComplet(),
                'inscrit'     => $participation !== null,
                'statut'      => $participation?->getStatut(),
            ];
        }

        // ── Mes missions à venir (assignées ou confirmées) ────────────────
        // findMissionsAVenir est déjà borné au user ; on filtre en plus par
        // CLUB : un bénévole de deux clubs ne voit ici que le club courant.
        $missions = [];
        foreach ($this->affectationRepository->findMissionsAVenir($user) as $a) {
            if ($a->getRencontre()?->getClub()?->getId() !== $club->getId()) {
                continue;
            }
            $missions[] = $this->ligneMission($a);
        }

        // ── Mes candidatures en attente de validation ─────────────────────
        $candidatures = [];
        foreach ($this->affectationRepository->findCandidaturesEnAttente($user) as $a) {
            if ($a->getRencontre()?->getClub()?->getId() !== $club->getId()) {
                continue;
            }
            $candidatures[] = $this->ligneMission($a);
        }

        return new JsonResponse([
            'evenements'   => $evenements,
            'missions'     => $missions,
            'candidatures' => $candidatures,
        ]);
    }

    /**
     * POST — s'inscrire ou se désinscrire d'un événement, en un tap.
     * Corps : {"action": "inscrire"} ou {"action": "desinscrire"}
     */
    #[Route('/api/club/evenements/{id}/participation', name: 'api_club_evenement_participation', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function participation(Request $request, int $id): JsonResponse
    {
        $club = $this->clubCourant($request);
        $user = $this->utilisateur();

        $evenement = $this->evenementRepository->find($id);
        // 404 uniforme : inexistant ou d'un autre club, même réponse.
        if (!$evenement instanceof Evenement || $evenement->getClub()?->getId() !== $club->getId()) {
            return $this->erreur('Événement introuvable.', 404);
        }

        $data = json_decode($request->getContent(), true);
        $action = is_array($data) ? (string) ($data['action'] ?? '') : '';

        $existante = $this->participationRepository->trouverPour($user, $evenement);

        if ($action === 'inscrire') {
            // Les mêmes règles que le web, dans le même ordre.
            if (!$evenement->isPublie()) {
                return $this->erreur('Inscriptions fermées (événement non publié ou annulé).', 409);
            }
            if ($evenement->isComplet()) {
                return $this->erreur('Événement complet.', 409);
            }
            if ($existante !== null) {
                return new JsonResponse(['succes' => true, 'inscrit' => true, 'note' => 'Déjà inscrit.']);
            }

            $p = new EvenementParticipation();
            $p->setEvenement($evenement);
            $p->setUser($user);
            $p->setStatut(EvenementParticipation::STATUT_INSCRIT);
            $this->em->persist($p);
            $this->em->flush();

            return new JsonResponse(['succes' => true, 'inscrit' => true]);
        }

        if ($action === 'desinscrire') {
            if ($existante === null) {
                return new JsonResponse(['succes' => true, 'inscrit' => false, 'note' => 'Pas inscrit.']);
            }
            $this->em->remove($existante);
            $this->em->flush();

            return new JsonResponse(['succes' => true, 'inscrit' => false]);
        }

        return $this->erreur('Action invalide : "inscrire" ou "desinscrire".', 400);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /** Une mission sérialisée pour l'app : le poste + l'affiche du match. */
    private function ligneMission(AffectationMatch $a): array
    {
        $r = $a->getRencontre();
        return [
            'id'        => $a->getId(),
            'role'      => $a->getRole(),
            'roleLabel' => $a->getRoleLabel(),
            'statut'    => $a->getStatut(),
            'rencontre' => [
                'id'         => $r?->getId(),
                'date'       => $r?->getDate()?->format(\DateTimeInterface::ATOM),
                'equipe'     => $r?->getEquipe()?->getNom(),
                'adversaire' => $r?->getAdversaire(),
                'domicile'   => $r?->isDomicile() ?? true,
                'lieu'       => $r?->getLieu(),
            ],
        ];
    }
}
