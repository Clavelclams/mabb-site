<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\EvaluationFfbbRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\SessionStatsLiveRepository;
use App\Repository\Sport\TirFfbbRepository;
use App\Service\Stats\ActionMatchAggregator;
use App\Service\Stats\JoueurStatsAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B10/B11 — PIRB Stats personnelles.
 *
 * Routes :
 *   GET /stats                  → résumé saison
 *   GET /stats/match/{id}       → détail d'un match
 *
 * Source : EvaluationMatch (saisi par coach).
 * Phase 2 : fusion avec Stats Live promues officielles (B11.2).
 */
class PirbStatsController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly JoueurStatsAggregator $aggregator,
        private readonly EvaluationFfbbRepository $evalFfbbRepo,
        private readonly TirFfbbRepository $tirFfbbRepo,
        private readonly SessionStatsLiveRepository $sessionRepo,
        private readonly ActionMatchAggregator $actionAggregator,
        private readonly ActionMatchRepository $actionMatchRepo,
    ) {}

    #[Route('/stats', name: 'pirb_stats', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $stats = $this->aggregator->statsSaison($joueur);

        return $this->render('pirb/stats.html.twig', [
            'joueur' => $joueur,
            'stats'  => $stats,
        ]);
    }

    #[Route('/stats/match/{id}', name: 'pirb_stats_match', methods: ['GET'])]
    public function match(Rencontre $rencontre): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        // [B22a 12/06/2026] Filtre RGPD : la joueuse ne voit cette page QUE si
        // elle a joué/été convoquée pour ce match. Si elle est de la même équipe
        // mais n'a pas joué, on accepte aussi (transparence intra-équipe).
        $appartientEquipe = $joueur->getEquipe()?->getId() === $rencontre->getEquipe()?->getId();
        if (!$appartientEquipe) {
            throw $this->createAccessDeniedException('Ce match ne concerne pas ton équipe.');
        }

        $eval = $this->aggregator->evalForMatch($joueur, $rencontre->getId());

        // [B22b 12/06/2026] Stats FFBB extraites du PDF resume :
        //   - statsFfbbMoi : ma propre ligne (peut être null si pas dans le match)
        //   - statsFfbbEquipe : toutes les lignes de mon équipe (pour vue ensemble)
        // Affiché dans un toggle "Stats coach (saisie manuelle)" / "Stats FFBB (officielles)"
        $statsFfbbMoi = $this->evalFfbbRepo->findForJoueurEtRencontre($joueur, $rencontre->getId());
        $statsFfbbEquipe = $this->evalFfbbRepo->findForRencontre($rencontre);

        // [B22c 12/06/2026] Tirs FFBB de la joueuse pour ce match (juste compteur V1)
        $tirsFfbbMatch = $this->tirFfbbRepo->createQueryBuilder('t')
            ->where('t.joueur = :j')
            ->andWhere('t.rencontre = :r')
            ->andWhere('t.source = :s')
            ->setParameter('j', $joueur)
            ->setParameter('r', $rencontre)
            ->setParameter('s', \App\Entity\Sport\TirFfbb::SOURCE_FFBB)
            ->getQuery()
            ->getResult();

        // [B22d 12/06/2026] 3ème source : stats agrégées depuis SessionStatsLive OFFICIELLE
        // ActionMatchAggregator::agreger() retourne array de stats individuelles
        // calculées depuis les ActionMatch de la session officielle.
        $sessionOfficielle = $this->sessionRepo->findOfficielleByRencontre($rencontre);
        $statsLiveMoi = null;
        $statsLiveEquipe = [];
        if ($sessionOfficielle !== null) {
            $statsLiveMoi = $this->actionAggregator->agreger($joueur, $rencontre);

            // Pour chaque joueuse de l'équipe : agréger ses stats
            $coequipieres = $rencontre->getEquipe()?->getJoueurs() ?? [];
            foreach ($coequipieres as $j) {
                if (!$j->isActive()) continue;
                $agg = $this->actionAggregator->agreger($j, $rencontre);
                // On ne garde que si la joueuse a effectivement des actions
                if (!empty($agg) && ($agg['nb_actions'] ?? 0) > 0) {
                    $statsLiveEquipe[] = ['joueur' => $j, 'stats' => $agg];
                }
            }
        }

        return $this->render('pirb/stats_match.html.twig', [
            'joueur'             => $joueur,
            'rencontre'          => $rencontre,
            'eval'               => $eval,
            'stats_ffbb_moi'     => $statsFfbbMoi,
            'stats_ffbb_equipe'  => $statsFfbbEquipe,
            'tirs_ffbb_match'    => $tirsFfbbMatch,
            'session_officielle' => $sessionOfficielle,
            'stats_live_moi'     => $statsLiveMoi,
            'stats_live_equipe'  => $statsLiveEquipe,
        ]);
    }

    /**
     * [B22c 12/06/2026] Shot chart cumulé saison de la joueuse.
     * Affiche le terrain SVG avec tous les tirs FFBB (+ Stats Live future) marqués
     * sur l'ensemble des matchs joués. Permet d'identifier les zones de tir efficaces.
     */
    #[Route('/stats/shotchart', name: 'pirb_stats_shotchart', methods: ['GET'])]
    public function shotchart(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Tous les tirs réussis FFBB de la joueuse (saison toutes confondues)
        $tirsFfbb = $this->tirFfbbRepo->findForJoueur($joueur);

        // [B22d 12/06/2026] Tirs depuis ActionMatch (Stats Live officielles)
        // → on cherche les actions TYPE_TIR_*_REUSSI avec position X/Y
        $typesReussis = [
            \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_INT_REUSSI,
            \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_EXT_REUSSI,
            \App\Entity\Sport\ActionMatch::TYPE_TIR_3PT_REUSSI,
        ];
        $tirsLive = $this->actionMatchRepo->createQueryBuilder('a')
            ->leftJoin('a.session', 's')->addSelect('s')
            ->leftJoin('s.rencontre', 'r')->addSelect('r')
            ->where('a.joueur = :j')
            ->andWhere('a.type IN (:types)')
            ->andWhere('s.statut = :off')
            ->setParameter('j', $joueur)
            ->setParameter('types', $typesReussis)
            ->setParameter('off', \App\Entity\Sport\SessionStatsLive::STATUT_OFFICIELLE)
            ->getQuery()
            ->getResult();

        // Décompte par type pour stats globales (FFBB + Live confondus)
        $countByType = ['2pt_int' => 0, '2pt_ext' => 0, '3pt' => 0, 'inconnu' => 0];
        $countWithPos = 0;
        foreach ($tirsFfbb as $t) {
            $type = $t->getTypeTir() ?? 'inconnu';
            $countByType[$type] = ($countByType[$type] ?? 0) + 1;
            if ($t->getPositionX() !== null && $t->getPositionY() !== null) {
                $countWithPos++;
            }
        }
        foreach ($tirsLive as $t) {
            $type = match ($t->getType()) {
                \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_INT_REUSSI => '2pt_int',
                \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_EXT_REUSSI => '2pt_ext',
                \App\Entity\Sport\ActionMatch::TYPE_TIR_3PT_REUSSI     => '3pt',
                default => 'inconnu',
            };
            $countByType[$type] = ($countByType[$type] ?? 0) + 1;
            if ($t->getPositionX() !== null && $t->getPositionY() !== null) {
                $countWithPos++;
            }
        }

        return $this->render('pirb/shotchart.html.twig', [
            'joueur'         => $joueur,
            'tirs'           => $tirsFfbb,         // FFBB (V1 sans coords)
            'tirs_live'      => $tirsLive,         // Stats Live (avec coords X/Y % 0-100)
            'count_by_type'  => $countByType,
            'count_with_pos' => $countWithPos,
            'total'          => count($tirsFfbb) + count($tirsLive),
        ]);
    }

    /**
     * [B22a 12/06/2026] Téléchargement sécurisé d'un PDF FFBB par la joueuse.
     * RGPD : seules les joueuses de l'équipe de la rencontre peuvent télécharger.
     *
     * Routes :
     *   GET /stats/match/{id}/pdf/feuille
     *   GET /stats/match/{id}/pdf/resume
     *   GET /stats/match/{id}/pdf/positions
     */
    #[Route('/stats/match/{id}/pdf/{type}', name: 'pirb_stats_match_pdf', methods: ['GET'], requirements: ['type' => 'feuille|resume|positions'])]
    public function downloadPdf(Rencontre $rencontre, string $type, string $projectDir): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        // Vérif RGPD : même équipe que la rencontre
        if ($joueur->getEquipe()?->getId() !== $rencontre->getEquipe()?->getId()) {
            throw $this->createAccessDeniedException();
        }

        $relativePath = $rencontre->getPdfPath($type);
        if ($relativePath === null) {
            throw $this->createNotFoundException('PDF non disponible pour ce match.');
        }

        $absolutePath = rtrim($projectDir, '/') . '/public/' . ltrim($relativePath, '/');
        if (!is_file($absolutePath)) {
            throw $this->createNotFoundException('Fichier introuvable sur le serveur.');
        }

        $labels = ['feuille' => 'feuille-match', 'resume' => 'resume-stats', 'positions' => 'positions-tirs'];
        $filename = sprintf(
            'mabb-%s-vs-%s-%s.pdf',
            $rencontre->getDate()?->format('Y-m-d') ?? 'date',
            preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($rencontre->getAdversaire() ?? 'adv')),
            $labels[$type]
        );

        return $this->file($absolutePath, $filename);
    }
}
