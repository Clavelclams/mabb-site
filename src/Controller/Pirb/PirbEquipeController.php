<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\JoueurEquipe;
use App\Repository\Sport\JoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function index(JoueurRepository $joueurRepo): Response
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

        $equipe = $monJoueur->getEquipe();
        if ($equipe === null) {
            return $this->render('pirb/equipe.html.twig', [
                'mon_joueur'   => $monJoueur,
                'equipe'       => null,
                'coequipiers'  => [],
                'message'      => 'Tu n\'es pas encore affecté à une équipe. Contacte ton coach.',
            ]);
        }

        // Tous les joueurs ACTIFS de l'équipe, sauf moi
        $coequipiers = $joueurRepo->createQueryBuilder('j')
            ->where('j.equipe = :equipe')
            ->andWhere('j.isActive = :actif')
            ->andWhere('j.id != :moi')
            ->setParameter('equipe', $equipe)
            ->setParameter('actif', true)
            ->setParameter('moi', $monJoueur->getId())
            ->orderBy('j.numeroMaillot', 'ASC')
            ->addOrderBy('j.nom', 'ASC')
            ->getQuery()
            ->getResult();

        // V1.6.1 — Autres équipes où la joueuse joue (doublage / surclassement / réserve)
        // On filtre sur saison courante + actif=true + type != principale.
        $autresEquipes = [];
        $now = new \DateTimeImmutable();
        $moisNum = (int) $now->format('n');
        $anneeDebut = $moisNum >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;
        $saisonCourante = $anneeDebut . '-' . ($anneeDebut + 1);

        foreach ($monJoueur->getAffectations() as $aff) {
            if (!$aff->isActif()) continue;
            if ($aff->getSaison() !== $saisonCourante) continue;
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
            'mon_joueur'      => $monJoueur,
            'equipe'          => $equipe,
            'coequipiers'     => $coequipiers,
            'autres_equipes'  => $autresEquipes,
            'message'         => null,
        ]);
    }
}
