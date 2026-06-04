<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Joueur;
use App\Gamification\BadgeCatalog;
use App\Gamification\NiveauCatalog;
use App\Gamification\XpCalculator;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ClassementController — leaderboard XP/niveau du club.
 *
 * Une seule action /classement qui affiche TOUS les joueurs actifs du club
 * triés par XP de la saison courante (ou globale). Filtres par équipe.
 *
 * Choix UX : pas d'affichage des rangs sur les enfants U7/U9/U11 (compétition
 * entre tout-petits = pas le but du sport collectif). Pour V1 on affiche tout
 * sans filtre, et l'admin coupera plus tard si problème.
 */
class ClassementController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly JoueurRepository $joueurRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly XpCalculator $xpCalculator,
        private readonly JoueurBadgeRepository $badgeRepository,
    ) {}

    #[Route('/classement', name: 'manager_classement', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Filtres
        $equipeId = (int) ($request->query->get('equipe_id') ?? 0);
        $portee   = (string) $request->query->get('portee', 'saison'); // 'saison' | 'tout'

        // Récup joueurs actifs du club (filtrés équipe si demandé)
        $criteres = ['club' => $club, 'isActive' => true];
        if ($equipeId > 0) {
            $equipe = $this->equipeRepository->find($equipeId);
            if ($equipe && $equipe->getClub()->getId() === $club->getId()) {
                $criteres['equipe'] = $equipe;
            }
        }
        $joueurs = $this->joueurRepository->findBy($criteres);

        // Calcul XP pour chaque joueur (peut être lourd sur gros effectif —
        // V2 : cache Redis ou colonne matérialisée)
        $classement = [];
        foreach ($joueurs as $joueur) {
            $xp = $portee === 'tout'
                ? $this->xpCalculator->xpTotal($joueur)
                : $this->xpCalculator->xpSaison($joueur);
            $niveau = NiveauCatalog::depuisXp($xp);
            $nbBadges = count($this->badgeRepository->codesBadgesPourJoueur(
                $joueur,
                $portee === 'tout' ? null : $this->saisonCourante()
            ));

            $classement[] = [
                'joueur'   => $joueur,
                'xp'       => $xp,
                'niveau'   => $niveau,
                'nb_badges' => $nbBadges,
            ];
        }

        // Tri par XP desc
        usort($classement, fn($a, $b) => $b['xp'] <=> $a['xp']);

        // Liste équipes pour le dropdown filtre
        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['nom' => 'ASC']
        );

        return $this->render('manager/classement/index.html.twig', [
            'club'        => $club,
            'classement'  => $classement,
            'equipes'     => $equipes,
            'equipe_id'   => $equipeId,
            'portee'      => $portee,
            'saison'      => $this->saisonCourante(),
        ]);
    }

    private function saisonCourante(): string
    {
        $now = new \DateTimeImmutable();
        $mois = (int) $now->format('n');
        $anneeDebut = $mois >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        return $anneeDebut . '-' . ($anneeDebut + 1);
    }
}
