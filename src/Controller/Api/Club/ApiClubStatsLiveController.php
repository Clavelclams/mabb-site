<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\User;
use App\Entity\Sport\ActionMatch;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\SessionStatsLive;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\EquipeRepository;
use App\Service\SaisonService;
use App\Service\Stats\SessionStatsLivePromoteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-5 04/08/2026] Stats Live depuis le téléphone du coach — la VALIDATION.
 *
 * LE CONSTAT QUI JUSTIFIE CET ENDPOINT : au 04/08, en production, AUCUNE
 * session Stats Live n'a jamais été promue officielle. Autrement dit, tout
 * ce que les bénévoles saisissent à la table de marque n'est JAMAIS remonté
 * aux joueuses. Le maillon manquant n'est pas la saisie (elle existe, sur
 * tablette, côté web) : c'est la VALIDATION, un geste de dix secondes que
 * le coach oublie parce qu'il faut un ordinateur.
 *
 * Ici, le coach voit sur son téléphone les matchs saisis non validés, et
 * promeut la bonne session en deux taps. C'est le chaînon qui rend tout le
 * système Stats Live utile chaque week-end.
 *
 * CE QU'ON NE FAIT PAS (assumé, cadrage doc 32) : la SAISIE live native sur
 * téléphone. Un écran de 6 pouces en plein match, c'est douteux ; la cible
 * de saisie reste la tablette (web). Le téléphone consulte et valide.
 *
 *   GET  /api/club/stats-live/a-valider   → les matchs qui attendent
 *   POST /api/club/sessions/{id}/promouvoir → valider une session
 */
final class ApiClubStatsLiveController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly SessionStatsLivePromoteur $promoteur,
        private readonly SaisonService $saisonService,
    ) {}

    /**
     * GET — les rencontres de MES équipes qui ont des sessions de saisie
     * mais AUCUNE officielle : celles qui attendent le geste du coach.
     *
     * Réponse : { aValider: [{rencontre, sessions:[{id, nom, statut,
     * nbActions, creePar, creeLe}]}], total }
     *
     * `nbActions` est là pour choisir en connaissance de cause quand deux
     * bénévoles ont saisi le même match : on promeut la plus complète.
     */
    #[Route('/api/club/stats-live/a-valider', name: 'api_club_stats_live_a_valider', methods: ['GET'])]
    public function aValider(Request $request): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $idsEquipes = $this->idsEquipesAccessibles($this->utilisateur(), $club);
        if ($idsEquipes === []) {
            return new JsonResponse(['aValider' => [], 'total' => 0]);
        }

        // Les sessions non archivées des rencontres de mes équipes, rencontre
        // jointe pour éviter le N+1. On regroupe ensuite en PHP : à l'échelle
        // d'un club (quelques matchs par week-end), c'est simple et suffisant.
        /** @var SessionStatsLive[] $sessions */
        $sessions = $this->em->createQueryBuilder()
            ->select('s', 'r', 'e')
            ->from(SessionStatsLive::class, 's')
            ->join('s.rencontre', 'r')
            ->join('r.equipe', 'e')
            ->where('e.id IN (:equipes)')
            ->andWhere('s.statut != :archivee')
            ->setParameter('equipes', $idsEquipes)
            ->setParameter('archivee', SessionStatsLive::STATUT_ARCHIVEE)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults(120)
            ->getQuery()
            ->getResult();

        // Regroupe par rencontre, et ÉCARTE celles qui ont déjà une officielle :
        // elles ne sont plus « à valider », le travail est fait.
        /** @var array<int, array{rencontre: Rencontre, sessions: SessionStatsLive[]}> $parRencontre */
        $parRencontre = [];
        $dejaValidees = [];
        foreach ($sessions as $s) {
            $r = $s->getRencontre();
            if ($r === null) {
                continue;
            }
            $rid = (int) $r->getId();
            if ($s->isOfficielle()) {
                $dejaValidees[$rid] = true;
                continue;
            }
            $parRencontre[$rid] ??= ['rencontre' => $r, 'sessions' => []];
            $parRencontre[$rid]['sessions'][] = $s;
        }
        $parRencontre = array_diff_key($parRencontre, $dejaValidees);

        // Nombre d'actions par session, en UNE requête groupée (pas de N+1).
        $nbParSession = [];
        if ($parRencontre !== []) {
            $idsSessions = [];
            foreach ($parRencontre as $entree) {
                foreach ($entree['sessions'] as $s) {
                    $idsSessions[] = (int) $s->getId();
                }
            }
            $lignes = $this->em->createQueryBuilder()
                ->select('IDENTITY(a.session) AS sid, COUNT(a.id) AS nb')
                ->from(ActionMatch::class, 'a')
                ->where('a.session IN (:ids)')
                ->setParameter('ids', $idsSessions)
                ->groupBy('a.session')
                ->getQuery()
                ->getArrayResult();
            foreach ($lignes as $l) {
                $nbParSession[(int) $l['sid']] = (int) $l['nb'];
            }
        }

        $sortie = [];
        foreach ($parRencontre as $entree) {
            $r = $entree['rencontre'];
            $sortie[] = [
                'rencontre' => [
                    'id'         => $r->getId(),
                    'date'       => $r->getDate()?->format(\DateTimeInterface::ATOM),
                    'equipe'     => ['id' => $r->getEquipe()?->getId(), 'nom' => $r->getEquipe()?->getNom()],
                    'adversaire' => $r->getAdversaire(),
                    'domicile'   => $r->isDomicile(),
                ],
                'sessions' => array_map(static fn(SessionStatsLive $s) => [
                    'id'        => $s->getId(),
                    'nom'       => $s->getNom(),
                    'statut'    => $s->getStatut(),
                    'nbActions' => $nbParSession[(int) $s->getId()] ?? 0,
                    'creePar'   => trim(($s->getCreatedBy()?->getPrenom() ?? '') . ' ' . ($s->getCreatedBy()?->getNom() ?? '')) ?: null,
                    'creeLe'    => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ], $entree['sessions']),
            ];
        }

        return new JsonResponse(['aValider' => $sortie, 'total' => count($sortie)]);
    }

    /**
     * POST — promeut une session en OFFICIELLE.
     *
     * C'est LE geste. Derrière, le promoteur fait tout le travail déjà en
     * place côté web : rétrograde l'éventuelle officielle précédente,
     * clôture les présences terrain restées ouvertes (le cinq du buzzer),
     * et génère les EvaluationMatch → les stats apparaissent chez les
     * joueuses. Zéro logique dupliquée : même service que le web.
     */
    #[Route('/api/club/sessions/{id}/promouvoir', name: 'api_club_session_promouvoir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function promouvoir(Request $request, int $id): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $session = $this->em->find(SessionStatsLive::class, $id);
        $rencontre = $session?->getRencontre();

        // 404 uniforme : session inexistante, d'un autre club, ou d'une équipe
        // que je n'encadre pas → même réponse, on ne confirme rien.
        if ($session === null || $rencontre === null
            || $rencontre->getClub()?->getId() !== $club->getId()
            || !in_array((int) $rencontre->getEquipe()?->getId(), $this->idsEquipesAccessibles($this->utilisateur(), $club), true)) {
            return $this->erreur('Session introuvable.', 404);
        }

        try {
            $this->promoteur->promouvoirOfficielle($session, $this->utilisateur());
        } catch (\RuntimeException $e) {
            // « déjà officielle », « archivée »… : un état, pas un droit → 409.
            return $this->erreur($e->getMessage(), 409);
        }

        return new JsonResponse([
            'succes'  => true,
            'message' => 'Stats validées. Elles sont maintenant visibles par les joueuses.',
        ]);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Les identifiants d'équipes que ce compte peut couvrir : toutes celles
     * du club pour un dirigeant, sinon les liens CoachEquipe. Même règle que
     * les autres contrôleurs club — un coach ne valide pas les stats d'une
     * équipe qui n'est pas la sienne.
     *
     * @return int[]
     */
    private function idsEquipesAccessibles(User $user, \App\Entity\Core\Club $club): array
    {
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(\App\Entity\Core\UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        $saison = $this->saisonService->getSaisonActive();

        if ($estDirigeant) {
            $equipes = $this->equipeRepository->findBy(['club' => $club, 'saison' => $saison, 'isActive' => true]);
            return array_map(static fn(Equipe $e) => (int) $e->getId(), $equipes);
        }

        $ids = [];
        foreach ($this->coachEquipeRepository->findByCoach($user, $saison) as $lien) {
            $equipe = $lien->getEquipe();
            if ($equipe !== null && $equipe->getClub()?->getId() === $club->getId()) {
                $ids[] = (int) $equipe->getId();
            }
        }
        return $ids;
    }
}
