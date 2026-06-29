<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Gamification\BadgeCatalog;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\JoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B12 — PIRB Bilan 4 axes (gamification visible côté joueuse).
 *
 * Page /pirb/bilan : affiche pour chaque axe (Régularité, Performance,
 * Bénévolat, Employé) la progression badges + XP cumulé.
 */
class PirbBilanController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository          $joueurRepo,
        private readonly JoueurBadgeRepository     $badgeRepo,
        private readonly BilanCompetenceRepository $bilanRepo,
    ) {}

    #[Route('/bilan', name: 'pirb_bilan', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $codesDebloques = $this->badgeRepo->codesBadgesPourJoueur($joueur);
        $catalog = BadgeCatalog::all();

        $axes = [
            BadgeCatalog::AXE_REGULARITE  => ['label' => 'Régularité',          'emoji' => '📅', 'color' => '#1d4ed8', 'badges' => []],
            BadgeCatalog::AXE_PERFORMANCE => ['label' => 'Performance basket',   'emoji' => '🏀', 'color' => '#ea580c', 'badges' => []],
            BadgeCatalog::AXE_BENEVOLAT   => ['label' => 'Vie de club',          'emoji' => '🤝', 'color' => '#16a34a', 'badges' => []],
            BadgeCatalog::AXE_EMPLOYE     => ['label' => 'Performance pro',      'emoji' => '💼', 'color' => '#9333ea', 'badges' => []],
        ];

        foreach ($catalog as $code => $info) {
            $axe = $info['axe'];
            if (!isset($axes[$axe])) {
                continue; // axe transverse ou inconnu
            }
            $axes[$axe]['badges'][] = [
                'code'        => $code,
                'nom'         => $info['nom'],
                'description' => $info['description'],
                'icone'       => $info['icone'],
                'debloque'    => in_array($code, $codesDebloques, true),
            ];
        }

        // Compteurs par axe
        foreach ($axes as $axeKey => $axeData) {
            $total = count($axeData['badges']);
            $deb   = count(array_filter($axeData['badges'], fn($b) => $b['debloque']));
            $axes[$axeKey]['total']      = $total;
            $axes[$axeKey]['debloques']  = $deb;
            $axes[$axeKey]['percent']    = $total > 0 ? (int) round(($deb / $total) * 100) : 0;
        }

        return $this->render('pirb/bilan.html.twig', [
            'joueur' => $joueur,
            'axes'   => $axes,
            'total_debloques' => count($codesDebloques),
            'total_disponibles' => count($catalog),
        ]);
    }

    /**
     * B16 — Bilan de compétences basketballistiques (lecture seule).
     *
     * Affiche le dernier bilan VALIDÉ de la joueuse connectée.
     * Accessible uniquement quand le coach a passé le bilan en statut VALIDE.
     */
    #[Route('/bilan/competences', name: 'pirb_bilan_competences', methods: ['GET'])]
    public function competences(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Dernier bilan validé seulement — les brouillons restent invisibles côté PIRB
        $bilan = $this->bilanRepo->findDernierValide($joueur);

        // Tous les bilans validés pour historique
        $historique = $this->bilanRepo->findByJoueur($joueur);
        $historique  = array_filter($historique, fn($b) => $b->isValide());

        return $this->render('pirb/bilan_competences.html.twig', [
            'joueur'     => $joueur,
            'bilan'      => $bilan,
            'historique' => array_values($historique),
        ]);
    }
}
