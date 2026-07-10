<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Pirb\SeancePlayground;
use App\Entity\Sport\Joueur;
use App\Repository\Pirb\SeancePlaygroundRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbPlaygroundController — [Engagement V1, 10/07/2026]
 * La boucle qui donne envie de revenir : les séances montent, le classement
 * descend.
 *
 *   POST /api/pirb/playground/seance      → enregistre une séance (jeu auto)
 *   GET  /api/pirb/playground/classement  → classement du club (?mode=tir|dribble)
 *
 * RÈGLES PRODUIT (Clavel, 10/07) :
 *  - Classement = PRÉNOM + CLUB uniquement. Jamais le nom de famille —
 *    public mineur, on expose le minimum qui permet de se reconnaître
 *    entre coéquipières.
 *  - Périmètre V1 : LE CLUB (même règle RGPD que /commu). L'inter-club
 *    attendra le cadrage du consentement parental.
 *  - Fenêtre : 7 derniers jours glissants — un classement hebdo se remet à
 *    zéro tout seul, chaque lundi est une nouvelle chance d'être première
 *    (c'est ça qui fait revenir, un classement all-time fige les positions).
 */
class PirbPlaygroundController extends AbstractController
{
    private const MODES = ['tir', 'dribble'];
    private const FENETRE_JOURS = 7;
    /** Les 8 zones du contrat (ShotChartCalculator / types/pirb.ts). */
    private const ZONES = [
        'raquette', 'courte_distance', 'mi_distance',
        '3pts_coin_g', '3pts_coin_d', '3pts_aile_g', '3pts_aile_d', '3pts_haut',
    ];

    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly SeancePlaygroundRepository $seanceRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/pirb/playground/seance
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/playground/seance', name: 'api_pirb_playground_seance', methods: ['POST'])]
    public function enregistrerSeance(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps JSON attendu.'], Response::HTTP_BAD_REQUEST);
        }

        $mode = $data['mode'] ?? null;
        if (!in_array($mode, self::MODES, true)) {
            return new JsonResponse(['error' => 'Mode invalide (tir|dribble).'], Response::HTTP_BAD_REQUEST);
        }

        // Bornage systématique : les chiffres viennent du client (jeu local).
        // Entier, jamais négatif, plafonné à des valeurs humainement possibles.
        $borner = static fn(mixed $v, int $max): int => min(max((int) (is_numeric($v) ? $v : 0), 0), $max);

        $seance = SeancePlayground::creer(
            $moi,
            $mode,
            $borner($data['reussis'] ?? 0, 2000),
            $borner($data['rates'] ?? 0, 2000),
            $borner($data['score'] ?? 0, 1_000_000),
            $borner($data['dureeSecondes'] ?? 0, 7200),
        );

        // [Recap v4] Détail par tir, OPTIONNEL (mode tir auto). Revalidé
        // entrée par entrée — zone hors liste ou forme inattendue = ignorée,
        // plafond 300. On ne stocke QUE reussi + zone (pas de données brutes).
        if (isset($data['tirs']) && is_array($data['tirs'])) {
            $tirs = [];
            foreach (array_slice($data['tirs'], 0, 300) as $t) {
                if (
                    is_array($t)
                    && isset($t['reussi'], $t['zone'])
                    && is_bool($t['reussi'])
                    && in_array($t['zone'], self::ZONES, true)
                ) {
                    $tirs[] = ['reussi' => $t['reussi'], 'zone' => $t['zone']];
                }
            }
            if ($tirs !== []) {
                $seance->setTirs($tirs);
            }
        }

        $this->em->persist($seance);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'id' => $seance->getId()], Response::HTTP_CREATED);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/playground/classement?mode=tir|dribble
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/playground/classement', name: 'api_pirb_playground_classement', methods: ['GET'])]
    public function classement(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $mode = $request->query->get('mode', 'tir');
        if (!in_array($mode, self::MODES, true)) {
            return new JsonResponse(['error' => 'Mode invalide (tir|dribble).'], Response::HTTP_BAD_REQUEST);
        }

        $club = $moi->getClub();
        if ($club === null) {
            return new JsonResponse([]);
        }

        $depuis = new \DateTimeImmutable('-' . self::FENETRE_JOURS . ' days');
        $lignes = $this->seanceRepo->classementClub($club->getId(), $mode, $depuis);

        // Contrat types/pirb.ts::ClassementJoueuse — prénom + club SEULEMENT.
        $reponse = [];
        foreach ($lignes as $l) {
            $reponse[] = [
                'joueurId'     => $l['joueurId'],
                'prenom'       => $l['prenom'],
                'club'         => $club->getNom(),
                'bestScore'    => $l['bestScore'],
                'totalReussis' => $l['totalReussis'],
                'nbSeances'    => $l['nbSeances'],
                'estMoi'       => $l['joueurId'] === $moi->getId(),
            ];
        }

        return new JsonResponse($reponse);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Privé
    // ─────────────────────────────────────────────────────────────────────

    /** 3e copie de joueurOu404 → règle des trois atteinte : à factoriser en trait au prochain passage. */
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
