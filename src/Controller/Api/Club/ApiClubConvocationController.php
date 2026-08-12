<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Sport\Convocation;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\RencontreRepository;
use App\Service\ConvocationManager;
use App\Service\SaisonService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-3 04/08/2026] La convocation depuis le téléphone.
 *
 * C'EST LE CAS D'USAGE QUI JUSTIFIE L'APP À LUI SEUL. Aujourd'hui, un coach
 * doit ouvrir un ordinateur pour convoquer. Beaucoup n'en ont pas sous la
 * main, et la convocation se fait donc par messages, hors de l'outil — donc
 * sans trace, sans réponse centralisée, et sans rien qui remonte à la joueuse.
 *
 *   GET  /api/club/rencontres/{id}/convocation → l'effectif + qui est
 *        convoqué + les réponses reçues
 *   POST /api/club/rencontres/{id}/convocation → enregistre la liste
 *
 * TOUTES LES RÈGLES MÉTIER SONT DANS `ConvocationManager`, partagé avec le
 * web : filtre sur l'effectif réel, pas de re-notification, journalisation
 * d'une réponse effacée, push après enregistrement. Ce contrôleur ne fait que
 * traduire du JSON.
 *
 * DOUBLE CONTRÔLE D'ACCÈS, et les deux sont nécessaires :
 *   1. la rencontre appartient au club courant (résolu par le socle) ;
 *   2. le compte encadre RÉELLEMENT cette équipe (lien CoachEquipe), ou est
 *      dirigeant du club.
 *   Sans le second, un coach de U13 convoquerait chez les Séniors et verrait
 *   l'effectif nominatif d'une équipe qui n'est pas la sienne.
 *
 * PAS DE CSRF ICI, et c'est normal : l'API est sans état (`stateless: true`),
 * authentifiée par jeton Bearer. Il n'y a pas de cookie de session, donc pas
 * de falsification de requête intersites possible.
 */
final class ApiClubConvocationController extends ApiClubController
{
    public function __construct(
        private readonly ConvocationManager $convocations,
        private readonly RencontreRepository $rencontreRepository,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly SaisonService $saisonService,
    ) {}

    /**
     * GET — tout ce qu'il faut pour afficher l'écran de convocation.
     *
     * Réponse :
     *   rencontre : {id, date, adversaire, domicile, lieu, equipe}
     *   effectif  : [{joueurId, prenom, nom, numeroMaillot, convoquee,
     *                 reponse, motif, repondueAt}]
     *   resume    : {convoquees, presentes, absentes, incertaines, sansReponse}
     *
     * L'app affiche la liste et coche ce qui est déjà convoqué. Le résumé
     * évite de le recalculer côté mobile — et garantit que web et app
     * comptent pareil.
     */
    #[Route(
        '/api/club/rencontres/{id}/convocation',
        name: 'api_club_convocation_lire',
        methods: ['GET'],
        requirements: ['id' => '\d+'],
    )]
    public function lire(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);

        $effectif   = $this->convocations->effectifConvocable($rencontre);
        $existantes = $this->convocations->convocationsExistantes($rencontre);

        $lignes = [];
        $resume = [
            'convoquees'  => 0,
            'presentes'   => 0,
            'absentes'    => 0,
            'incertaines' => 0,
            'sansReponse' => 0,
        ];

        foreach ($effectif as $joueurId => $joueur) {
            $convocation = $existantes[$joueurId] ?? null;
            $reponse = $convocation?->getReponse();

            if ($convocation !== null) {
                $resume['convoquees']++;
                match ($reponse) {
                    Convocation::REPONSE_PRESENT   => $resume['presentes']++,
                    Convocation::REPONSE_ABSENT    => $resume['absentes']++,
                    Convocation::REPONSE_INCERTAIN => $resume['incertaines']++,
                    default                        => $resume['sansReponse']++,
                };
            }

            $lignes[] = [
                'joueurId'      => $joueurId,
                'prenom'        => $joueur->getPrenom(),
                'nom'           => $joueur->getNom(),
                'numeroMaillot' => $joueur->getNumeroMaillot(),
                'convoquee'     => $convocation !== null,
                'reponse'       => $reponse,
                'motif'         => $convocation?->getMotif(),
                'repondueAt'    => $convocation?->getRepondueAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse([
            'rencontre' => [
                'id'         => $rencontre->getId(),
                'date'       => $rencontre->getDate()?->format(\DateTimeInterface::ATOM),
                'adversaire' => $rencontre->getAdversaire(),
                'domicile'   => $rencontre->isDomicile(),
                'lieu'       => $rencontre->getLieu(),
                'equipe'     => [
                    'id'  => $rencontre->getEquipe()?->getId(),
                    'nom' => $rencontre->getEquipe()?->getNom(),
                ],
            ],
            'effectif' => $lignes,
            'resume'   => $resume,
        ]);
    }

    /**
     * POST — enregistre la liste convoquée.
     *
     * Corps attendu : {"joueurs": [12, 45, 78]}
     *
     * La liste est REMPLACÉE, pas complétée : ce qui n'est pas dans le tableau
     * est retiré. C'est le même comportement que la case à cocher du web —
     * l'app doit donc toujours envoyer la liste complète, pas un delta.
     */
    #[Route(
        '/api/club/rencontres/{id}/convocation',
        name: 'api_club_convocation_enregistrer',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function enregistrer(Request $request, int $id): JsonResponse
    {
        $rencontre = $this->rencontreAutorisee($request, $id);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['joueurs']) || !is_array($data['joueurs'])) {
            return $this->erreur('Corps invalide : attendu {"joueurs": [ids]}.', 400);
        }

        try {
            $bilan = $this->convocations->appliquer($rencontre, $data['joueurs']);
        } catch (\InvalidArgumentException $e) {
            return $this->erreur($e->getMessage(), 422);
        }

        return new JsonResponse([
            'succes' => true,
            'bilan'  => $bilan,
        ]);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Charge la rencontre en vérifiant que le compte a le droit d'y toucher.
     *
     * @throws ApiClubException 404 si la rencontre n'existe pas, n'est pas
     *         dans le club courant, ou concerne une équipe non encadrée.
     *         Toujours 404 et jamais 403 : on ne confirme pas l'existence
     *         d'une rencontre à laquelle on n'a pas droit.
     */
    private function rencontreAutorisee(Request $request, int $id): Rencontre
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $rencontre = $this->rencontreRepository->find($id);

        if (!$rencontre instanceof Rencontre
            || $rencontre->getClub()?->getId() !== $club->getId()) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }

        $equipe = $rencontre->getEquipe();
        if ($equipe === null) {
            throw new ApiClubException(
                "Cette rencontre n'a pas d'équipe : impossible de convoquer.",
                422
            );
        }

        // Dirigeant (ou super-admin) : tout le club. Sinon, uniquement les
        // équipes réellement encadrées.
        $user = $this->utilisateur();
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(\App\Entity\Core\UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if (!$estDirigeant && !$this->coachEquipeRepository->estCoachDeEquipe($user, $equipe)) {
            throw new ApiClubException('Rencontre introuvable.', 404);
        }

        return $rencontre;
    }
}
