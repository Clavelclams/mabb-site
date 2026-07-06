<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\JoueurEquipe;
use App\Repository\Sport\JoueurRepository;
use App\Service\SaisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbEquipeController — page "Mon équipe" pour le joueur connecté.
 *
 * PIRB Phase V1.3 : permet à un joueur de voir ses coéquipières/iers.
 * Card par joueur (avatar + nom + n°), clic → profil public PIRB.
 *
 * SÉCURITÉ :
 *   - Affiche UNIQUEMENT les joueurs ACTIFS de l'équipe du user connecté
 *   - Exclut le user lui-même de la liste
 *   - Si user pas lié à un Joueur ou Joueur sans équipe → page d'aide
 */
class PirbEquipeController extends AbstractController
{
    /**
     * Liste des coéquipiers du user connecté.
     * GET pirb.mabb.fr/equipe
     */
    #[Route('/equipe', name: 'pirb_equipe', methods: ['GET'])]
    public function index(JoueurRepository $joueurRepo, SaisonService $saisonService, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $monJoueur = $joueurRepo->findOneBy(['user' => $user]);
        if ($monJoueur === null) {
            return $this->render('pirb/equipe.html.twig', [
                'mon_joueur'   => null,
                'equipe'       => null,
                'coequipiers'  => [],
                'message'      => 'Aucune fiche joueur n\'est liée à ton compte. Contacte le staff du club pour activer ton profil.',
            ]);
        }

        // [V2.4c 06/07/2026] SAISON AFFICHÉE = saison active par défaut
        // (bascule auto au 1er juillet), consultable en arrière via
        // ?saison=2025-2026. En début de saison, tant que les équipes ne
        // sont pas recomposées, la page dit clairement "pas encore
        // d'équipe cette saison" au lieu d'afficher l'équipe de l'an passé.
        $saisonActive = $saisonService->getSaisonActive();
        $saison = (string) $request->query->get('saison', $saisonActive);
        if (!$saisonService->isValide($saison)) {
            $saison = $saisonActive;
        }
        $saisonPrecedente = $saisonService->getSaisonPrecedente($saison);

        $equipe = $monJoueur->equipePourSaison($saison);
        if ($equipe === null) {
            $aUneEquipeAvant = $monJoueur->aUneEquipeEnSaison($saisonPrecedente);
            return $this->render('pirb/equipe.html.twig', [
                'mon_joueur'        => $monJoueur,
                'equipe'            => null,
                'coequipiers'       => [],
                'saison'            => $saison,
                'saison_precedente' => $aUneEquipeAvant ? $saisonPrecedente : null,
                'message'           => $saison === $saisonActive && $aUneEquipeAvant
                    ? sprintf('Les équipes de la saison %s ne sont pas encore composées. Ton coach s\'en occupe bientôt !', $saison)
                    : 'Tu n\'es pas encore affectée à une équipe. Contacte ton coach.',
            ]);
        }

        // Tous les joueurs ACTIFS de l'équipe, sauf moi.
        // [V2.4c] Deux sources fusionnées : affectations JoueurEquipe de la
        // saison (équipes composées via passage-saison) + lien direct legacy
        // (données historiques d'avant la table pivot).
        $viaAffectations = $joueurRepo->createQueryBuilder('j')
            ->join('j.affectations', 'a')
            ->where('a.equipe = :equipe')
            ->andWhere('a.saison = :saison')
            ->andWhere('a.actif = true')
            ->andWhere('j.isActive = true')
            ->andWhere('j.id != :moi')
            ->setParameter('equipe', $equipe)
            ->setParameter('saison', $saison)
            ->setParameter('moi', $monJoueur->getId())
            ->getQuery()
            ->getResult();
        $viaLegacy = $joueurRepo->createQueryBuilder('j')
            ->where('j.equipe = :equipe')
            ->andWhere('j.isActive = true')
            ->andWhere('j.id != :moi')
            ->setParameter('equipe', $equipe)
            ->setParameter('moi', $monJoueur->getId())
            ->getQuery()
            ->getResult();
        // Fusion dédupliquée + tri n° maillot puis nom
        $parId = [];
        foreach (array_merge($viaAffectations, $viaLegacy) as $j) {
            $parId[$j->getId()] = $j;
        }
        $coequipiers = array_values($parId);
        usort($coequipiers, static fn($a, $b) =>
            [($a->getNumeroMaillot() ?? 999), (string) $a->getNom()]
            <=> [($b->getNumeroMaillot() ?? 999), (string) $b->getNom()]);

        // V1.6.1 — Autres équipes où la joueuse joue (doublage / surclassement / réserve)
        // [V2.4c] Filtrées sur la MÊME saison que la page (plus de calcul local).
        $autresEquipes = [];
        foreach ($monJoueur->getAffectations() as $aff) {
            if (!$aff->isActif()) continue;
            if ($aff->getSaison() !== $saison) continue;
            if ($aff->isPrincipale()) continue;
            $autresEquipes[] = [
                'equipe'      => $aff->getEquipe(),
                'type'        => $aff->getType(),
                'type_label'  => JoueurEquipe::TYPE_LABELS[$aff->getType()] ?? $aff->getType(),
                'type_couleur'=> JoueurEquipe::TYPE_COULEURS[$aff->getType()] ?? 'gray',
                'notes'       => $aff->getNotes(),
            ];
        }

        return $this->render('pirb/equipe.html.twig', [
            'mon_joueur'        => $monJoueur,
            'equipe'            => $equipe,
            'coequipiers'       => $coequipiers,
            'autres_equipes'    => $autresEquipes,
            'saison'            => $saison,
            'saison_precedente' => $monJoueur->aUneEquipeEnSaison($saisonPrecedente) ? $saisonPrecedente : null,
            'message'           => null,
        ]);
    }
}
