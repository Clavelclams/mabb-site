<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\AffectationMatchRepository;
use App\Repository\Sport\RencontreRepository;
use App\Service\Otm\OtmService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-11 14/08/2026] L'ORGANISATION DE MATCH depuis le téléphone.
 *
 * LE BESOIN (demande Clavel) : « placer les employés, services civiques,
 * bénévoles » sur les postes d'une rencontre — chrono, e-marque, buvette…
 * C'est le kanban web (ManagerRencontreStaffController::postes), version
 * mobile : le dirigeant place tout le monde, le bénévole se place lui-même.
 *
 * RÈGLE D'OR : AUCUNE règle métier ici. Tout passe par OtmService
 * (fenêtre J-7 → mercredi, interdictions de poste, poste titulaire pris,
 * anti-répétition 2×/jour). Si une règle change, elle change pour le web
 * ET l'app en même temps — c'est exactement pour ça qu'OtmService existe.
 *
 * DROITS (mêmes que le web) :
 *   - voir l'orga           : tout membre du club (CLUB_MEMBER)
 *   - se placer soi-même    : tout membre, pendant la fenêtre
 *   - placer les autres     : encadrement (dirigeant/coach/staff), sans fenêtre
 *   - valider / rejeter les candidatures, saisie libre : encadrement
 *
 * ENDPOINTS :
 *   GET  /api/club/rencontres/{id}/orga                     → l'état complet
 *   POST /api/club/rencontres/{id}/orga/placer              → placer / retirer
 *   POST /api/club/orga/affectations/{aid}/valider          → candidature → confirmée
 *   POST /api/club/orga/affectations/{aid}/rejeter          → candidature supprimée
 */
final class ApiClubOrgaController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AffectationMatchRepository $affectationRepository,
        private readonly RencontreRepository $rencontreRepository,
        private readonly OtmService $otmService,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // GET — l'état des postes d'une rencontre
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/club/rencontres/{id}/orga', name: 'api_club_orga', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function orga(int $id, Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);
        $rencontre = $this->rencontreAutorisee($id, $club->getId());

        $estAdmin = $this->estEncadrant($user, $club->getId());
        $parRole = $this->affectationRepository->findByRencontre($rencontre);

        $postes = [];
        $placesIds = [];
        foreach (AffectationMatch::ROLES as $code => $libelle) {
            $titulaire = null;
            $renforts = [];
            $candidats = [];
            foreach ($parRole[$code] ?? [] as $a) {
                /** @var AffectationMatch $a */
                $ligne = $this->ligneAffectation($a);
                if ($a->isCandidature()) {
                    $candidats[] = $ligne;
                    continue;
                }
                if (!$a->isActif()) {
                    continue;
                }
                if ($a->getUser() !== null) {
                    $placesIds[] = $a->getUser()->getId();
                }
                if ($a->isTitulaire()) {
                    // Un seul titulaire par poste (contrainte métier) — si la
                    // base en contient plusieurs par accident, on garde le
                    // premier et les autres passent en renfort à l'affichage.
                    if ($titulaire === null) {
                        $titulaire = $ligne;
                    } else {
                        $renforts[] = $ligne;
                    }
                } else {
                    $renforts[] = $ligne;
                }
            }
            $postes[] = [
                'code'      => $code,
                'libelle'   => $libelle,
                'titulaire' => $titulaire,
                'renforts'  => $renforts,
                'candidats' => $candidats,
                // Vacant = personne de disponible dessus (un ABSENT ne couvre pas).
                'vacant'    => $titulaire === null || ($titulaire['statut'] === AffectationMatch::STATUT_ABSENT),
            ];
        }

        // Le vivier — uniquement pour l'encadrement (c'est lui qui place les
        // autres). Un bénévole n'a pas à voir l'annuaire du club sur son tel.
        $disponibles = [];
        if ($estAdmin) {
            foreach ($this->membresActifs($club->getId()) as $membre) {
                if (!in_array($membre->getId(), $placesIds, true)) {
                    $disponibles[] = [
                        'id'  => $membre->getId(),
                        'nom' => trim(($membre->getPrenom() ?? '') . ' ' . ($membre->getNom() ?? '')),
                    ];
                }
            }
        }

        $f = $this->otmService->fenetre($rencontre);

        return new JsonResponse([
            'rencontre' => [
                'id'         => $rencontre->getId(),
                'adversaire' => $rencontre->getAdversaire(),
                // ATOM comme le reste de l'API club : la date PORTE l'heure
                // (datetime_immutable en base, pas de champ heure séparé).
                'date'       => $rencontre->getDate()?->format(\DateTimeInterface::ATOM),
                'domicile'   => $rencontre->isDomicile(),
                'lieu'       => $rencontre->getLieu(),
                'equipe'     => $rencontre->getEquipe()?->getNom(),
            ],
            'fenetre' => [
                'ouverture' => $f['ouverture']?->format('Y-m-d'),
                'fermeture' => $f['fermeture']?->format('Y-m-d'),
                'ouverte'   => $f['ouverte'],
                'fermee'    => $f['fermee'],
                'pasEncore' => $f['pas_encore'],
            ],
            'estAdmin'    => $estAdmin,
            'monId'       => $user->getId(),
            'postes'      => $postes,
            'disponibles' => $disponibles,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // POST — placer / retirer quelqu'un (le « drop » du kanban)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Corps JSON :
     *   { "userId": 12, "role": "CHRONO" }            → placer un membre
     *   { "userId": 12, "role": null }                → le remettre au vivier
     *   { "nomLibre": "Sam SC", "role": "BUVETTE",
     *     "heureRdv": "12h30" }                       → saisie libre (admin)
     *
     * Miroir exact de postesDeplacer (web) pour les membres, et de assigner
     * (web) pour la saisie libre. Un membre simple ne place QUE lui-même.
     */
    #[Route('/api/club/rencontres/{id}/orga/placer', name: 'api_club_orga_placer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function placer(int $id, Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);
        $rencontre = $this->rencontreAutorisee($id, $club->getId());

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->erreur('Corps de requête invalide.', 400);
        }

        $userId   = (int) ($data['userId'] ?? 0);
        $role     = isset($data['role']) && $data['role'] !== null ? trim((string) $data['role']) : '';
        $nomLibre = trim((string) ($data['nomLibre'] ?? ''));
        $heureRdv = trim((string) ($data['heureRdv'] ?? ''));

        $estAdmin = $this->estEncadrant($user, $club->getId());

        // ── Saisie libre (service civique / externe sans compte) ──────────
        if ($userId === 0 && $nomLibre !== '') {
            if (!$estAdmin) {
                return $this->erreur("Seul l'encadrement peut placer une personne sans compte.", 403);
            }
            if (!isset(AffectationMatch::ROLES[$role])) {
                return $this->erreur('Poste inconnu.', 400);
            }
            // Même geste que le web « assigner » : la saisie libre REMPLACE le
            // titulaire en place (c'est l'admin qui décide, pas de fenêtre).
            foreach ($this->affectationRepository->findByRencontre($rencontre)[$role] ?? [] as $existante) {
                if ($existante->isActif() && $existante->isTitulaire()) {
                    $this->em->remove($existante);
                }
            }
            $a = (new AffectationMatch())
                ->setRencontre($rencontre)
                ->setNomLibre($nomLibre)
                ->setHeureRdv($heureRdv !== '' ? $heureRdv : null)
                ->setRole($role)
                ->setStatut(AffectationMatch::STATUT_ASSIGNE);
            $this->em->persist($a);
            $this->em->flush();

            return new JsonResponse(['ok' => true, 'role' => $role, 'assistant' => false]);
        }

        // ── Membre du club ────────────────────────────────────────────────
        $cible = $userId > 0 ? $this->em->getRepository(User::class)->find($userId) : null;
        if ($cible === null) {
            return $this->erreur('Personne introuvable.', 404);
        }

        // Un membre simple ne déplace QUE sa propre carte (même règle que le web).
        if (!$estAdmin && $cible->getId() !== $user->getId()) {
            return $this->erreur('Tu ne peux placer que toi-même.', 403);
        }

        // La cible doit être membre du club — sans quoi un id deviné suffirait
        // à inscrire n'importe qui (le web passe par la liste du club, l'API
        // doit refaire le contrôle).
        if (!$this->estMembreDuClub($cible, $club->getId())) {
            return $this->erreur('Cette personne ne fait pas partie du club.', 404);
        }

        // Une personne ne tient qu'UN poste par match : on retire ses
        // affectations actives avant de la reposer.
        foreach ($this->affectationRepository->findByRencontre($rencontre) as $liste) {
            foreach ($liste as $a) {
                /** @var AffectationMatch $a */
                if ($a->isCouvert() && $a->getUser()?->getId() === $cible->getId()) {
                    $this->em->remove($a);
                }
            }
        }

        // role vide → retour au vivier, c'est tout.
        if ($role === '') {
            $this->em->flush();
            return new JsonResponse(['ok' => true, 'role' => null]);
        }

        $this->em->flush(); // la règle « poste déjà pris » doit voir le retrait

        // Titulaire si le poste est libre, sinon renfort (« assisté de »).
        $titulaire = $this->affectationRepository->findActiveByRencontreAndRole($rencontre, $role);
        $assistant = $titulaire !== null && $titulaire->isTitulaire();

        $refus = $this->otmService->motifRefus($rencontre, $cible, $role, $assistant, $estAdmin);
        if ($refus !== null) {
            return $this->erreur($refus, 409);
        }

        $a = (new AffectationMatch())
            ->setRencontre($rencontre)
            ->setUser($cible)
            ->setRole($role)
            ->setEstAssistant($assistant)
            // L'admin assigne ; un membre qui SE place candidate (le web fait
            // pareil : bouton « je m'inscris » → CANDIDAT, kanban admin → ASSIGNE).
            ->setStatut($estAdmin ? AffectationMatch::STATUT_ASSIGNE : AffectationMatch::STATUT_CANDIDAT);

        $this->em->persist($a);
        $this->em->flush();

        return new JsonResponse([
            'ok'        => true,
            'role'      => $role,
            'assistant' => $assistant,
            'statut'    => $a->getStatut(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // POST — valider / rejeter une candidature (encadrement)
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/club/orga/affectations/{aid}/valider', name: 'api_club_orga_valider', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function valider(int $aid, Request $request): JsonResponse
    {
        [$affectation] = $this->affectationEncadree($aid, $request);

        if (!$affectation->isCandidature()) {
            return $this->erreur("Cette inscription n'est plus en attente.", 409);
        }

        // Même règle que le web : valider une candidature TITULAIRE évince les
        // autres candidatures titulaires du même poste. Les renforts, eux,
        // cohabitent — on ne supprime pas les candidatures de renfort.
        $rencontre = $affectation->getRencontre();
        $role      = $affectation->getRole();
        if ($affectation->isTitulaire()) {
            foreach ($this->affectationRepository->findByRencontre($rencontre)[$role] ?? [] as $a) {
                if ($a->getId() !== $affectation->getId() && $a->isCandidature() && $a->isTitulaire()) {
                    $this->em->remove($a);
                }
            }
        }

        $affectation->setStatut(AffectationMatch::STATUT_CONFIRME);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/club/orga/affectations/{aid}/rejeter', name: 'api_club_orga_rejeter', methods: ['POST'], requirements: ['aid' => '\d+'])]
    public function rejeter(int $aid, Request $request): JsonResponse
    {
        [$affectation] = $this->affectationEncadree($aid, $request);

        if (!$affectation->isCandidature()) {
            return $this->erreur("Cette inscription n'est plus en attente.", 409);
        }

        $this->em->remove($affectation);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Privé
    // ────────────────────────────────────────────────────────────────────

    /**
     * La rencontre existe ET appartient au club courant — sinon 404 uniforme
     * (jamais 403 : ne pas confirmer l'existence d'une donnée d'un autre club).
     */
    private function rencontreAutorisee(int $id, int $clubId): Rencontre
    {
        $rencontre = $this->rencontreRepository->find($id);
        if ($rencontre === null || $rencontre->getClub()?->getId() !== $clubId) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }
        return $rencontre;
    }

    /**
     * Charge une affectation, vérifie le club via sa rencontre, exige
     * l'encadrement. Retourne [affectation] (tableau pour garder la
     * possibilité d'y ajouter le club plus tard sans casser les appels).
     *
     * @return array{0: AffectationMatch}
     */
    private function affectationEncadree(int $aid, Request $request): array
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);

        $affectation = $this->em->getRepository(AffectationMatch::class)->find($aid);
        if ($affectation === null
            || $affectation->getRencontre()?->getClub()?->getId() !== $club->getId()) {
            throw new ApiClubException('Inscription introuvable.', 404);
        }

        if (!$this->estEncadrant($user, $club->getId())) {
            throw new ApiClubException("Réservé à l'encadrement du club.", 403);
        }

        return [$affectation];
    }

    /** Dirigeant, coach ou staff de CE club (ou super-admin). */
    private function estEncadrant(User $user, int $clubId): bool
    {
        if ($this->estSuperAdmin($user)) {
            return true;
        }
        $roles = $this->rolesParClub($user)[$clubId]['roles'] ?? [];
        return array_intersect($roles, [
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
        ]) !== [];
    }

    private function estMembreDuClub(User $user, int $clubId): bool
    {
        if ($this->estSuperAdmin($user)) {
            return true;
        }
        return isset($this->rolesParClub($user)[$clubId]);
    }

    /**
     * Tous les membres actifs du club — même requête que le kanban web
     * (UserClubRole actif + compte actif), triée par nom.
     *
     * @return User[]
     */
    private function membresActifs(int $clubId): array
    {
        return $this->em->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->join(UserClubRole::class, 'ucr', 'WITH', 'ucr.user = u')
            ->where('ucr.club = :club')->setParameter('club', $clubId)
            ->andWhere('ucr.status = :actif')->setParameter('actif', UserClubRole::STATUS_ACTIVE)
            ->andWhere('u.isActive = true')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    private function ligneAffectation(AffectationMatch $a): array
    {
        return [
            'affectationId' => $a->getId(),
            'userId'        => $a->getUser()?->getId(),
            'nom'           => $a->getPersonneNom(),
            'statut'        => $a->getStatut(),
            'assistant'     => $a->isEstAssistant(),
            'heureRdv'      => $a->getHeureRdv(),
            'note'          => $a->getNote(),
        ];
    }
}
