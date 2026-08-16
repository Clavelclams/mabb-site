<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Presence;
use App\Entity\Sport\Seance;
use App\Gamification\BadgeChecker;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\SeanceRepository;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-4 14/08/2026] LE POINTAGE DE SÉANCE depuis le téléphone.
 *
 * Le geste du mardi soir : le coach arrive au gymnase, ouvre l'app, coche
 * qui est là. Trente secondes, debout, entre deux ballons. C'est LE cas
 * d'usage mobile par excellence — le web l'a toujours eu, mais personne
 * n'ouvre un ordinateur au bord d'un terrain.
 *
 *   GET  /api/club/seances                → mes séances (défaut : 7 prochains jours)
 *   GET  /api/club/seances/{id}/pointage  → la grille (joueuses + état)
 *   POST /api/club/seances/{id}/pointage  → enregistre (upsert complet)
 *
 * MÊMES RÈGLES QUE LE WEB (PresenceController::pointageSeance) :
 *   - joueuses ACTIVES de l'équipe de la séance, triées par nom ;
 *   - upsert : une Presence existe déjà → mise à jour, sinon création
 *     (source 'manuel') ;
 *   - motif d'absence seulement si absente ;
 *   - après le flush, sync des badges gamification AVEC isolation par
 *     joueuse (bugfix B21 : un plantage badge ne casse jamais le pointage).
 *
 * ACCÈS : encadrement du club, et l'équipe de la séance doit être une de
 * MES équipes (lien CoachEquipe) — sauf dirigeant/super-admin qui voit tout.
 * 404 uniforme sinon, comme partout dans l'API.
 */
final class ApiClubSeanceController extends ApiClubController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeanceRepository $seanceRepository,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly SaisonService $saisonService,
        private readonly BadgeChecker $badgeChecker,
        private readonly LoggerInterface $logger,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // GET — mes séances à pointer
    // ────────────────────────────────────────────────────────────────────

    /**
     * ?periode=avenir (défaut, aujourd'hui → +7 jours) | recentes (−7 jours →
     * aujourd'hui inclus, pour pointer après coup une séance d'hier).
     *
     * Chaque séance porte son état de pointage (fait / à faire + compte) :
     * l'app peut afficher « ⚠️ appel non fait » sans second appel réseau.
     */
    #[Route('/api/club/seances', name: 'api_club_seances', methods: ['GET'])]
    public function seances(Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $saison = $this->saisonAvecEquipes($club, $this->saisonService->getSaisonActive());
        $equipes = $this->equipesAccessibles($user, $club, $saison);

        if ($equipes === []) {
            return new JsonResponse(['seances' => []]);
        }

        $periode = (string) $request->query->get('periode', 'avenir');
        $aujourdHui = new \DateTimeImmutable('today');
        if ($periode === 'recentes') {
            $debut = $aujourdHui->modify('-7 days');
            $fin   = $aujourdHui->modify('+1 day'); // aujourd'hui inclus
        } else {
            $debut = $aujourdHui;
            $fin   = $aujourdHui->modify('+8 days');
        }

        /** @var Seance[] $seances */
        $seances = $this->seanceRepository->createQueryBuilder('s')
            ->andWhere('s.equipe IN (:equipes)')->setParameter('equipes', $equipes)
            ->andWhere('s.date >= :debut')->setParameter('debut', $debut)
            ->andWhere('s.date < :fin')->setParameter('fin', $fin)
            ->orderBy('s.date', 'ASC')
            ->getQuery()->getResult();

        $lignes = [];
        foreach ($seances as $s) {
            $nbPresentes = 0;
            $nbPointees  = 0;
            foreach ($s->getPresences() as $p) {
                $nbPointees++;
                if ($p->isPresent()) {
                    $nbPresentes++;
                }
            }
            $lignes[] = [
                'id'          => $s->getId(),
                'date'        => $s->getDate()?->format(\DateTimeInterface::ATOM),
                'equipe'      => ['id' => $s->getEquipe()?->getId(), 'nom' => $s->getEquipe()?->getNom()],
                'type'        => $s->getType(),
                'intitule'    => $s->getTitreAffichage(),
                'lieu'        => $s->getLieu(),
                'dureeMinutes' => $s->getDureeMinutes(),
                'appelFait'   => $nbPointees > 0,
                'nbPresentes' => $nbPresentes,
                'nbPointees'  => $nbPointees,
            ];
        }

        return new JsonResponse(['periode' => $periode, 'seances' => $lignes]);
    }

    // ────────────────────────────────────────────────────────────────────
    // GET — la grille de pointage d'une séance
    // ────────────────────────────────────────────────────────────────────

    #[Route('/api/club/seances/{id}/pointage', name: 'api_club_pointage_lire', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function lire(int $id, Request $request): JsonResponse
    {
        $seance = $this->seanceAutorisee($id, $request);

        return new JsonResponse([
            'seance' => [
                'id'       => $seance->getId(),
                'date'     => $seance->getDate()?->format(\DateTimeInterface::ATOM),
                'equipe'   => $seance->getEquipe()?->getNom(),
                'type'     => $seance->getType(),
                'intitule' => $seance->getTitreAffichage(),
                'lieu'     => $seance->getLieu(),
            ],
            'joueuses' => $this->grille($seance),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // POST — enregistrer le pointage
    // ────────────────────────────────────────────────────────────────────

    /**
     * Corps JSON :
     *   { "presences": [ { "joueurId": 12, "present": true },
     *                    { "joueurId": 15, "present": false, "motif": "malade" } ] }
     *
     * CONTRAT : la liste est COMPLÈTE (toutes les joueuses de la grille),
     * comme le formulaire web. Une joueuse omise est considérée ABSENTE
     * sans motif — c'est le comportement des checkbox web (non cochée =
     * absente), on le garde pour que web et app écrivent pareil.
     */
    #[Route('/api/club/seances/{id}/pointage', name: 'api_club_pointage_enregistrer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function enregistrer(int $id, Request $request): JsonResponse
    {
        $seance = $this->seanceAutorisee($id, $request);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !is_array($data['presences'] ?? null)) {
            return $this->erreur('Corps de requête invalide : { presences: [...] } attendu.', 400);
        }

        // Indexation de ce qu'envoie l'app : joueurId → {present, motif}
        $envoyees = [];
        foreach ($data['presences'] as $ligne) {
            if (!is_array($ligne) || !isset($ligne['joueurId'])) {
                continue;
            }
            $envoyees[(int) $ligne['joueurId']] = [
                'present' => (bool) ($ligne['present'] ?? false),
                'motif'   => trim((string) ($ligne['motif'] ?? '')),
            ];
        }

        // Les joueuses ACTIVES de l'équipe — la seule liste qui compte. Un
        // joueurId envoyé qui n'en fait pas partie est IGNORÉ (anti-IDOR :
        // impossible de pointer une joueuse d'une autre équipe).
        $joueurs = array_filter(
            $seance->getEquipe()?->getJoueurs()->toArray() ?? [],
            static fn($j) => $j->isActive()
        );

        $existantes = [];
        foreach ($seance->getPresences() as $p) {
            $existantes[$p->getJoueur()?->getId()] = $p;
        }

        $crees = 0;
        $majs  = 0;
        foreach ($joueurs as $joueur) {
            $joueurId = (int) $joueur->getId();
            $etat = $envoyees[$joueurId] ?? ['present' => false, 'motif' => ''];

            $presence = $existantes[$joueurId] ?? null;
            if ($presence === null) {
                $presence = new Presence();
                $presence->setJoueur($joueur);
                $presence->setSeance($seance);
                $presence->setSource(Presence::SOURCE_MANUEL);
                $this->em->persist($presence);
                $crees++;
            } else {
                $majs++;
            }

            $presence->setPresent($etat['present']);
            $presence->setMotifAbsence(!$etat['present'] && $etat['motif'] !== '' ? $etat['motif'] : null);
        }

        $this->em->flush();

        // Gamification APRÈS le commit, isolée par joueuse (bugfix B21 du
        // web, même logique) : un badge qui plante ne casse pas le pointage.
        $nbBadges = 0;
        foreach ($joueurs as $joueur) {
            try {
                $nbBadges += count($this->badgeChecker->syncBadges($joueur));
            } catch (\Throwable $e) {
                $this->logger->error('BadgeChecker a planté pendant un pointage API', [
                    'joueur_id' => $joueur->getId(),
                    'seance_id' => $seance->getId(),
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            }
        }

        return new JsonResponse([
            'succes'    => true,
            'crees'     => $crees,
            'majs'      => $majs,
            'nbBadges'  => $nbBadges,
            'joueuses'  => $this->grille($seance),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Privé
    // ────────────────────────────────────────────────────────────────────

    /**
     * La séance existe, elle est du club courant, et son équipe est une des
     * MIENNES (ou je suis dirigeant/super-admin). 404 uniforme sinon.
     */
    private function seanceAutorisee(int $id, Request $request): Seance
    {
        $user = $this->utilisateur();
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $seance = $this->seanceRepository->find($id);
        if ($seance === null || $seance->getClub()?->getId() !== $club->getId()) {
            throw new ApiClubException('Séance introuvable.', 404);
        }

        $equipe = $seance->getEquipe();
        if ($equipe === null) {
            throw new ApiClubException("Cette séance n'a pas d'équipe : rien à pointer.", 422);
        }

        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || $this->estSuperAdmin($user);

        if (!$estDirigeant && !$this->coachEquipeRepository->estCoachDeEquipe($user, $equipe)) {
            throw new ApiClubException('Séance introuvable.', 404);
        }

        return $seance;
    }

    /**
     * La grille : joueuses actives de l'équipe + leur état de pointage.
     * `pointee` false = l'appel n'a pas encore touché cette joueuse (l'app
     * affiche alors la grille vierge, cases décochées).
     *
     * @return array<int, array<string, mixed>>
     */
    private function grille(Seance $seance): array
    {
        $presences = [];
        foreach ($seance->getPresences() as $p) {
            $presences[$p->getJoueur()?->getId()] = $p;
        }

        $joueurs = array_filter(
            $seance->getEquipe()?->getJoueurs()->toArray() ?? [],
            static fn($j) => $j->isActive()
        );
        usort($joueurs, static fn($a, $b) => strcmp((string) $a->getNom(), (string) $b->getNom()));

        $lignes = [];
        foreach ($joueurs as $j) {
            $p = $presences[$j->getId()] ?? null;
            $lignes[] = [
                'joueurId'      => $j->getId(),
                'prenom'        => $j->getPrenom(),
                'nom'           => $j->getNom(),
                'numeroMaillot' => $j->getNumeroMaillot(),
                'pointee'       => $p !== null,
                'presente'      => $p?->isPresent() ?? false,
                'motif'         => $p?->getMotifAbsence(),
            ];
        }

        return $lignes;
    }

    /**
     * Mes équipes : toutes celles du club si dirigeant/super-admin, sinon
     * celles du lien CoachEquipe — avec le filtre club que findByCoach()
     * ne fait pas (piège documenté, doc 39).
     *
     * @return Equipe[]
     */
    private function equipesAccessibles(User $user, Club $club, string $saison): array
    {
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || $this->estSuperAdmin($user);

        if ($estDirigeant) {
            return $this->equipeRepositorySocle->findBy(
                ['club' => $club, 'saison' => $saison, 'isActive' => true]
            );
        }

        $equipes = [];
        foreach ($this->coachEquipeRepository->findByCoach($user, $saison) as $lien) {
            $equipe = $lien->getEquipe();
            if ($equipe !== null && $equipe->getClub()?->getId() === $club->getId()) {
                $equipes[] = $equipe;
            }
        }
        return $equipes;
    }
}
