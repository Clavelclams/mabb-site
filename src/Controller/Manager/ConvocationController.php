<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\Rencontre;
use App\Security\Voter\ClubVoter;
use App\Service\ConvocationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ConvocationController — [13/07/2026] LE CHAÎNON MANQUANT.
 *
 * Constat de départ : la table `convocation` n'était écrite NULLE PART. Aucun
 * `new Convocation(...)` dans tout le projet. La fiche rencontre du Manager
 * affichait « Module convocations dans une prochaine itération ».
 *
 * Conséquence : l'espace joueuse (web ET app) lisait une table vide. L'écran de
 * convocations, l'API, le bloc « Ton prochain match » sur l'accueil : trois
 * tuyaux magnifiques branchés sur rien. Le coach ne pouvait pas convoquer.
 *
 * Ce contrôleur ferme la boucle : le coach coche son effectif, ça crée les
 * lignes, ça crée une notification, la joueuse la voit dans l'app et répond.
 *
 *   POST /manager/rencontres/{id}/convocations → enregistre la liste convoquée
 *
 * [VC-3 04/08/2026] LES RÈGLES MÉTIER ONT DÉMÉNAGÉ dans `ConvocationManager`.
 * L'app mobile a besoin exactement des mêmes (filtre sur l'effectif réel,
 * pas de re-notification, journalisation d'une réponse effacée). Les recopier
 * aurait garanti qu'elles divergent un jour. Ce contrôleur ne fait donc plus
 * que ce qui est propre au WEB : jeton CSRF, message flash, redirection.
 */
class ConvocationController extends AbstractController
{
    public function __construct(
        private readonly ConvocationManager $convocations,
    ) {}

    #[Route(
        '/rencontres/{id}/convocations',
        name: 'manager_rencontre_convocations',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function enregistrer(Request $request, Rencontre $rencontre): Response
    {
        // Le staff du club, et personne d'autre. Une convocation engage le club.
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        if (!$this->isCsrfTokenValid('convocations_' . $rencontre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        try {
            $bilan = $this->convocations->appliquer(
                $rencontre,
                array_map('intval', (array) $request->request->all('joueurs'))
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $this->addFlash('success', sprintf(
            '%d joueuse%s convoquée%s. %d ajout%s, %d retrait%s.',
            $bilan['convoquees'],
            $bilan['convoquees'] > 1 ? 's' : '',
            $bilan['convoquees'] > 1 ? 's' : '',
            $bilan['ajoutees'],
            $bilan['ajoutees'] > 1 ? 's' : '',
            $bilan['retirees'],
            $bilan['retirees'] > 1 ? 's' : '',
        ));

        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }
}
