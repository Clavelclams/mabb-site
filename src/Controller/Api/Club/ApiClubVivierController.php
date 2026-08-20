<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Convocation;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\RencontreRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-13 21/08/2026] LE RENFORT EN CASCADE — l'idée la plus forte du
 * brainstorm du 13/07, enfin construite (V1).
 *
 * LE PROBLÈME RÉEL : le coach de la A convoque 10 filles sur 14. Les 4
 * restantes sont LIBRES ce week-end — et le coach de la B, en manque
 * d'effectif, ne le sait pas. Personne n'outille ce casse-tête : ça se
 * règle par SMS, tard, mal.
 *
 * V1 (décisions prises selon les instincts notés au cadrage) :
 *   - vivier AUTOMATIQUE : toute joueuse ACTIVE du club qui n'est PAS déjà
 *     convoquée sur une rencontre du même jour. Pas de case « je la libère ».
 *   - le coach PIOCHE : l'ajout crée une convocation normale → la joueuse
 *     (ou ses parents) reçoit et peut refuser, comme n'importe quelle convoc.
 *   - double-booking : IMPOSSIBLE par construction (déjà convoquée le même
 *     jour = hors du vivier). Une joueuse qui enchaîne deux matchs
 *     volontairement reste gérable par le web.
 *   - les règles FFBB de surclassement : le coach connaît sa catégorie mieux
 *     qu'une heuristique — on AFFICHE l'équipe d'origine, il juge.
 *
 *   GET  /api/club/rencontres/{id}/vivier → les disponibles du jour
 *   POST /api/club/rencontres/{id}/vivier → { joueurId } : convoquer en renfort
 */
final class ApiClubVivierController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RencontreRepository $rencontreRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly ConvocationRepository $convocationRepository,
        private readonly CoachEquipeRepository $coachEquipeRepository,
    ) {}

    #[Route('/api/club/rencontres/{id}/vivier', name: 'api_club_vivier', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function vivier(int $id, Request $request): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($id, $request);

        $lignes = [];
        foreach ($this->disponibles($rencontre) as $j) {
            $lignes[] = [
                'joueurId'      => $j->getId(),
                'prenom'        => $j->getPrenom(),
                'nom'           => $j->getNom(),
                'numeroMaillot' => $j->getNumeroMaillot(),
                'equipe'        => $j->getEquipe()?->getNom(), // son équipe d'origine
            ];
        }

        return new JsonResponse(['vivier' => $lignes]);
    }

    #[Route('/api/club/rencontres/{id}/vivier', name: 'api_club_vivier_ajouter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouter(int $id, Request $request): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($id, $request);

        $data = json_decode($request->getContent(), true);
        $joueurId = is_array($data) ? (int) ($data['joueurId'] ?? 0) : 0;

        // La cible doit être DANS le vivier — pas seulement dans le club.
        // C'est le garde-fou anti double-booking ET anti-IDOR en un test.
        $cible = null;
        foreach ($this->disponibles($rencontre) as $j) {
            if ($j->getId() === $joueurId) { $cible = $j; break; }
        }
        if ($cible === null) {
            return $this->erreur(
                "Cette joueuse n'est pas disponible (déjà convoquée ce jour-là, ou hors du club).",
                409
            );
        }

        // Une convocation NORMALE : la joueuse/ses parents la reçoivent et
        // peuvent refuser — le renfort est une invitation, pas une réquisition.
        $convocation = new Convocation();
        $convocation->setRencontre($rencontre);
        $convocation->setJoueur($cible);
        $this->em->persist($convocation);
        $this->em->flush();

        return new JsonResponse([
            'succes' => true,
            'joueuse' => trim(($cible->getPrenom() ?? '') . ' ' . ($cible->getNom() ?? '')),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────

    /**
     * Les joueuses ACTIVES du club, non temporaires, qui ne sont convoquées
     * sur AUCUNE rencontre du même jour (celle-ci comprise) et qui ne sont
     * pas de l'équipe de la rencontre (elles, on les convoque normalement).
     *
     * @return Joueur[]
     */
    private function disponibles(Rencontre $rencontre): array
    {
        $club = $rencontre->getClub();
        $jour = $rencontre->getDate();
        if ($club === null || $jour === null) {
            return [];
        }

        // Toutes les convocations du club ce JOUR-là, en une requête.
        $debut = \DateTimeImmutable::createFromInterface($jour)->setTime(0, 0);
        $fin   = $debut->modify('+1 day');
        $dejaPrises = [];
        $convocations = $this->convocationRepository->createQueryBuilder('c')
            ->join('c.rencontre', 'r')
            ->andWhere('r.club = :club')->setParameter('club', $club)
            ->andWhere('r.date >= :debut')->setParameter('debut', $debut)
            ->andWhere('r.date < :fin')->setParameter('fin', $fin)
            ->getQuery()->getResult();
        foreach ($convocations as $c) {
            $dejaPrises[$c->getJoueur()?->getId()] = true;
        }

        $equipeId = $rencontre->getEquipe()?->getId();
        $resultat = [];
        foreach ($this->joueurRepository->findBy(
            ['club' => $club, 'isActive' => true, 'isTemporaire' => false],
            ['nom' => 'ASC']
        ) as $j) {
            if (isset($dejaPrises[$j->getId()])) continue;
            if ($j->getEquipe()?->getId() === $equipeId) continue;
            $resultat[] = $j;
        }
        return $resultat;
    }

    /** Même double contrôle que la convocation : club + encadrement réel. */
    private function rencontreAutorisee(int $id, Request $request): Rencontre
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $rencontre = $this->rencontreRepository->find($id);
        if ($rencontre === null || $rencontre->getClub()?->getId() !== $club->getId()) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }
        $equipe = $rencontre->getEquipe();
        if ($equipe === null) {
            throw new ApiClubException("Cette rencontre n'a pas d'équipe.", 422);
        }
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(UserClubRole::ROLE_DIRIGEANT, $roles, true) || $this->estSuperAdmin($user);
        if (!$estDirigeant && !$this->coachEquipeRepository->estCoachDeEquipe($user, $equipe)) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }
        return $rencontre;
    }
}
