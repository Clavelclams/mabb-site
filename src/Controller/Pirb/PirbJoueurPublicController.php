<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Gamification\BadgeCatalog;
use App\Repository\Sport\CotisationJoueurRepository;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\JoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbJoueurPublicController — affichage du profil PUBLIC d'une joueuse.
 *
 * PIRB Phase V1.2d : permet à un scout, un coach, un dirigeant, ou un autre
 * membre du club de consulter le profil d'une joueuse sans être la joueuse
 * elle-même. Le profil PIRB devient visible si :
 *   - la joueuse a coché "profil public" (visible MÊME aux non-connectés)
 *   - OU le user connecté a un UserClubRole actif sur le club de la joueuse
 *     (staff/coach/dirigeant peut toujours voir, même si profil privé)
 *
 * RGPD :
 *   - Si profil privé ET user non-staff → 404 (on ne révèle pas l'existence)
 *   - Email, téléphone, cotisation : JAMAIS visibles ici (réservés au profil
 *     perso de la joueuse + Manager pour le staff)
 *
 * Pas de modification possible depuis cette route — c'est de la lecture seule.
 */
class PirbJoueurPublicController extends AbstractController
{
    /**
     * Profil public d'une joueuse.
     * GET pirb.mabb.fr/joueuse/{id}
     */
    #[Route('/joueuse/{id}', name: 'pirb_joueuse_public', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        Joueur $joueur,
        JoueurBadgeRepository $joueurBadgeRepo,
        CotisationJoueurRepository $cotisationRepo,
        JoueurRepository $joueurRepo,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        // Détermine si le user est staff du club de la joueuse
        // (CLUB_STAFF, CLUB_ADMIN couvrent staff/coach/dirigeant via Voter)
        $estStaff = false;
        if ($user !== null && $joueur->getClub() !== null) {
            $estStaff = $this->isGranted('CLUB_STAFF', $joueur)
                     || $this->isGranted('CLUB_ADMIN', $joueur);
        }

        // V1.3 — Détermine si le user est COÉQUIPIER (même équipe que le joueur cible).
        // Un coéquipier peut voir le profil même s'il est privé (palier "équipe").
        $estCoequipier = false;
        if ($user !== null && $joueur->getEquipe() !== null) {
            $monJoueur = $joueurRepo->findOneBy(['user' => $user]);
            if ($monJoueur !== null
                && $monJoueur->getEquipe() !== null
                && $monJoueur->getEquipe()->getId() === $joueur->getEquipe()->getId()
            ) {
                $estCoequipier = true;
            }
        }

        // Vérification d'accès : profil public OU staff OU coéquipier
        if (!$joueur->isProfilPublic() && !$estStaff && !$estCoequipier) {
            // 404 (pas 403) pour ne pas révéler que cette joueuse existe —
            // un scout externe ne doit même pas pouvoir deviner les IDs.
            throw new NotFoundHttpException('Profil non disponible.');
        }

        // Charge les infos enrichies pour l'affichage
        $badgesEpingles = $joueur->getBadgesEpingles() ?? [];
        $catalogue = BadgeCatalog::all();

        // Stats simples : nombre de badges total débloqués (donne une idée du
        // niveau d'engagement). Le détail stats joueuse arrive en V1.5.
        $nbBadgesTotal = count($joueurBadgeRepo->codesBadgesPourJoueur($joueur, null));

        // Le staff voit en plus la cotisation et l'email (RGPD-protégé).
        // Les non-staff voient juste le profil public (bio, badges, réseaux).
        $cotisation = null;
        if ($estStaff) {
            $cotisation = $cotisationRepo->createQueryBuilder('c')
                ->where('c.joueur = :j')
                ->setParameter('j', $joueur)
                ->orderBy('c.saison', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $this->render('pirb/joueur_public.html.twig', [
            'joueur'           => $joueur,
            'badges_epingles'  => $badgesEpingles,
            'catalogue'        => $catalogue,
            'nb_badges_total'  => $nbBadgesTotal,
            'est_staff'        => $estStaff,
            'est_coequipier'   => $estCoequipier,
            'cotisation'       => $cotisation,
            'est_soi_meme'     => $user !== null
                                  && $joueur->getUser() !== null
                                  && $joueur->getUser()->getId() === $user->getId(),
        ]);
    }
}
