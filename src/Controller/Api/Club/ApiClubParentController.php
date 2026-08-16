<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\User;
use App\Entity\Sport\Convocation;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\ParentJoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-12 15/08/2026] LA VUE PARENT : mes enfants, leurs convocations,
 * et le droit d'y répondre à leur place.
 *
 * LE PUBLIC N°1 D'UN CLUB DE JEUNES, C'EST LES PARENTS. Une U11 n'a pas de
 * téléphone : la convocation du samedi, c'est PAPA OU MAMAN qui la reçoit,
 * la lit et y répond. Cette vue leur donne les trois gestes qui comptent :
 * voir les matchs à venir de leurs enfants, dire présent/absent, donner un
 * motif.
 *
 *   GET  /api/club/parent                             → mes enfants + convocations à venir
 *   POST /api/club/parent/convocations/{id}/repondre  → répondre au nom de l'enfant
 *
 * QUI EST « MON ENFANT » : un lien ParentJoueur au statut ACTIVE — jamais
 * pending (la validation du lien est un geste du dirigeant, sur le web).
 * C'est LE verrou de cette vue : toutes les données passent par ce lien.
 *
 * MÊMES RÈGLES QUE LA RÉPONSE JOUEUSE (PirbConvocationsController) :
 *   - anti-IDOR : la convocation doit concerner un de MES enfants (404
 *     uniforme sinon, et tentative loguée) ;
 *   - pas de réponse après la date du match ;
 *   - réponse ∈ {present, absent, incertain}, motif optionnel.
 */
final class ApiClubParentController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParentJoueurRepository $parentJoueurRepository,
        private readonly ConvocationRepository $convocationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // GET — mes enfants et leurs convocations à venir
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/club/parent', name: 'api_club_parent', methods: ['GET'])]
    public function parent(Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);

        $enfants = $this->mesEnfants($user, (int) $club->getId());
        if ($enfants === []) {
            return $this->erreur(
                "Aucun enfant rattaché à ton compte dans ce club. " .
                "Le lien parent-enfant se demande sur le web et doit être validé par le club.",
                403
            );
        }

        $aujourdHui = new \DateTimeImmutable('today');
        $lignes = [];
        foreach ($enfants as $enfant) {
            /** @var Convocation[] $convocations */
            $convocations = $this->convocationRepository->createQueryBuilder('c')
                ->join('c.rencontre', 'r')
                ->andWhere('c.joueur = :joueur')->setParameter('joueur', $enfant)
                ->andWhere('r.date >= :depuis')->setParameter('depuis', $aujourdHui)
                ->orderBy('r.date', 'ASC')
                ->getQuery()->getResult();

            $lignes[] = [
                'joueurId'      => $enfant->getId(),
                'prenom'        => $enfant->getPrenom(),
                'nom'           => $enfant->getNom(),
                'numeroMaillot' => $enfant->getNumeroMaillot(),
                'convocations'  => array_map(
                    fn(Convocation $c) => $this->ligneConvocation($c),
                    $convocations
                ),
            ];
        }

        return new JsonResponse(['enfants' => $lignes]);
    }

    // ────────────────────────────────────────────────────────────────────
    // POST — répondre au nom de l'enfant
    // ────────────────────────────────────────────────────────────────────

    /**
     * Corps JSON : { "reponse": "present"|"absent"|"incertain", "motif": "..." }
     */
    #[Route(
        '/api/club/parent/convocations/{id}/repondre',
        name: 'api_club_parent_repondre',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function repondre(int $id, Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);

        $convocation = $this->convocationRepository->find($id);

        // Anti-IDOR, double condition : la convocation existe, ET son joueur
        // est un de MES enfants (lien actif) dans CE club. 404 uniforme —
        // on ne confirme jamais l'existence d'une convocation d'autrui.
        $enfantsIds = array_map(
            static fn(Joueur $j) => $j->getId(),
            $this->mesEnfants($user, (int) $club->getId())
        );
        if ($convocation === null
            || !in_array($convocation->getJoueur()?->getId(), $enfantsIds, true)) {
            $this->logger->warning('Tentative de réponse convocation hors lien parent (API)', [
                'user_id'        => $user->getId(),
                'convocation_id' => $id,
            ]);
            return $this->erreur('Convocation introuvable.', 404);
        }

        // Verrou métier : le match est passé, la réponse n'a plus de sens.
        $rencontre = $convocation->getRencontre();
        if ($rencontre?->getDate() !== null && $rencontre->getDate() < new \DateTimeImmutable()) {
            return $this->erreur('Cette rencontre est déjà passée, on ne peut plus répondre.', 409);
        }

        $data = json_decode($request->getContent(), true);
        $reponse = is_array($data) ? (string) ($data['reponse'] ?? '') : '';
        if (!in_array($reponse, Convocation::REPONSES, true)) {
            return $this->erreur('Réponse invalide (present, absent ou incertain).', 400);
        }
        $motif = is_array($data) ? trim((string) ($data['motif'] ?? '')) : '';

        $convocation->setReponse($reponse);
        $convocation->setMotif($motif !== '' ? $motif : null);
        $this->em->flush();

        $this->logger->info('Réponse convocation par un parent (API)', [
            'convocation_id' => $convocation->getId(),
            'joueur_id'      => $convocation->getJoueur()?->getId(),
            'parent_id'      => $user->getId(),
            'reponse'        => $reponse,
        ]);

        return new JsonResponse([
            'succes'      => true,
            'convocation' => $this->ligneConvocation($convocation),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Privé
    // ────────────────────────────────────────────────────────────────────

    /**
     * Mes enfants dans CE club : liens ParentJoueur ACTIFS uniquement, sur
     * des joueuses actives. Le même filtre que la vue moi (VC-1) — un lien
     * en attente ne donne AUCUN droit.
     *
     * @return Joueur[]
     */
    private function mesEnfants(User $user, int $clubId): array
    {
        $enfants = [];
        foreach ($this->parentJoueurRepository->findBy(['parentUser' => $user]) as $lien) {
            if (!$lien->isActive()) {
                continue;
            }
            $joueur = $lien->getJoueur();
            if ($joueur === null || !$joueur->isActive()) {
                continue;
            }
            if ($joueur->getClub()?->getId() !== $clubId) {
                continue;
            }
            $enfants[] = $joueur;
        }
        return $enfants;
    }

    /** @return array<string, mixed> */
    private function ligneConvocation(Convocation $c): array
    {
        $r = $c->getRencontre();
        return [
            'id'      => $c->getId(),
            'reponse' => $c->getReponse(),
            'motif'   => $c->getMotif(),
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
