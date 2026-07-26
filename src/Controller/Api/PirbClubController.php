<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Gamification\XpCalculator;
use App\Repository\Sport\JoueurRepository;
use App\Service\SaisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbClubController — [Dé-mock, 26/07/2026] la vue « Mon club » devient
 * RÉELLE : l'écran affichait des équipes et des XP inventés (mock) même en
 * prod — le 5e des 7 mocks identifiés par l'audit (doc 17).
 *
 *   GET /api/pirb/club/overview → ClubOverview (contrat types/pirb.ts)
 *     { club: {id, nom}, equipes: [{id, nom, categorie, nbJoueuses,
 *       xpCumule}], xpTotal }
 *
 * LE CALCUL : pour chaque joueuse ACTIVE du club, l'XP de la saison vient
 * du MÊME XpCalculator que le niveau individuel (une seule vérité — si la
 * règle d'XP change, le club et le profil bougent ensemble). Agrégée par
 * équipe de la saison courante ; les joueuses sans équipe comptent dans le
 * total du club mais n'apparaissent dans aucune ligne.
 *
 * PERF, dit honnêtement : une requête d'XP par joueuse (~40-60 par club).
 * Correct à l'échelle d'un club aujourd'hui ; si un gros club arrive, la
 * parade est un agrégat SQL ou un cache court — pas besoin d'y payer la
 * complexité maintenant.
 *
 * RGPD : AUCUN nom de joueuse ne sort — que des agrégats par équipe.
 */
class PirbClubController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly XpCalculator $xpCalculator,
        private readonly SaisonService $saisonService,
    ) {}

    #[Route('/api/pirb/club/overview', name: 'api_pirb_club_overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $club = $moi->getClub();
        if ($club === null) {
            // Contrat app : null = « pas de club » → l'écran propose d'en
            // rejoindre un via Manager. Un 200 vide, pas une erreur.
            return new JsonResponse(null);
        }

        $saison = $this->saisonService->getSaisonCourante();

        // Agrégat par équipe : [equipeId => { id, nom, categorie, nbJoueuses, xpCumule }]
        $parEquipe = [];
        $xpTotal = 0;

        foreach ($this->joueurRepo->findByClub($club->getId()) as $j) {
            if (!$j instanceof Joueur || !$j->isActive()) { continue; }

            $xp = $this->xpCalculator->xpSaison($j);
            $xpTotal += $xp;

            $equipe = $j->equipePourSaison($saison) ?? $j->getEquipe();
            if ($equipe === null) { continue; } // compte dans le total, pas dans une ligne

            $id = $equipe->getId();
            if (!isset($parEquipe[$id])) {
                $parEquipe[$id] = [
                    'id'         => $id,
                    'nom'        => $equipe->getNom(),
                    'categorie'  => $equipe->getCategorie(),
                    'nbJoueuses' => 0,
                    'xpCumule'   => 0,
                ];
            }
            $parEquipe[$id]['nbJoueuses']++;
            $parEquipe[$id]['xpCumule'] += $xp;
        }

        // Meilleure équipe d'abord (l'écran retrie aussi — ceinture et bretelles).
        $equipes = array_values($parEquipe);
        usort($equipes, static fn(array $a, array $b) => $b['xpCumule'] <=> $a['xpCumule']);

        return new JsonResponse([
            'club'    => ['id' => $club->getId(), 'nom' => $club->getNom()],
            'equipes' => $equipes,
            'xpTotal' => $xpTotal,
        ]);
    }

    /** Même helper que les autres contrôleurs Api (trait dû — dette notée doc 18). */
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
