<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Presence;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\Seance;
use App\Gamification\BadgeChecker;
use App\Repository\Sport\PresenceRepository;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PresenceController — pointage des présences post-séance et post-rencontre.
 *
 * Logique métier :
 *   - Une Presence cible UNE Seance OU UNE Rencontre (contrainte XOR sur l'entité)
 *   - Pour une séance, les joueuses pointées sont celles affectées à l'équipe de la séance
 *   - Pour une rencontre, idem (les joueuses de l'équipe de la rencontre)
 *
 * Workflow coach :
 *   1. Après la séance, va sur la fiche séance → bouton "Faire le pointage"
 *   2. Arrive sur une page liste joueuses + checkbox "présente" (cochée par défaut)
 *   3. Décoche les absentes + remplit optionnellement le motif
 *   4. Submit → les Presence sont créées/mises à jour en masse
 *
 * Permet ensuite de calculer le taux de présence par joueuse sur toute la saison,
 * indicateur central pour les dossiers de subvention.
 */
class PresenceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PresenceRepository $presenceRepository,
        private readonly BadgeChecker $badgeChecker,
    ) {}

    /**
     * Sync gamification : recalcule les badges éligibles pour la liste de joueurs.
     * Appelée après chaque pointage pour débloquer les badges qui viennent
     * de devenir éligibles (ex: 5e séance consécutive).
     *
     * @param \App\Entity\Sport\Joueur[] $joueurs
     * @return int nombre total de badges nouvellement débloqués
     */
    private function syncBadgesPourJoueurs(array $joueurs): int
    {
        $totalNouveaux = 0;
        foreach ($joueurs as $joueur) {
            $nouveaux = $this->badgeChecker->syncBadges($joueur);
            $totalNouveaux += count($nouveaux);
        }
        return $totalNouveaux;
    }

    /**
     * Pointage des présences pour une séance.
     *
     *   GET  /seances/{id}/pointage   → affiche la grille de pointage
     *   POST /seances/{id}/pointage   → enregistre les présences
     */
    #[Route('/seances/{id}/pointage', name: 'manager_presence_seance', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function pointageSeance(Request $request, Seance $seance): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $seance);

        // Joueuses ACTIVES affectées à l'équipe de la séance
        $joueurs = array_filter(
            $seance->getEquipe()->getJoueurs()->toArray(),
            fn($j) => $j->isActive()
        );
        usort($joueurs, fn($a, $b) => strcmp($a->getNom(), $b->getNom()));

        // Présences existantes (pour réafficher l'état au cas où le coach revient)
        // Indexées par joueur_id pour accès rapide dans le template
        $presencesByJoueur = [];
        foreach ($seance->getPresences() as $p) {
            $presencesByJoueur[$p->getJoueur()->getId()] = $p;
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('pointage_seance_' . $seance->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_seance_show', ['id' => $seance->getId()]);
            }

            // Récupération des données du formulaire
            // Le formulaire envoie : present[joueur_id]=1 (si checkbox cochée), motif[joueur_id]="raison"
            $presents = $request->request->all('present');  // array de IDs cochés
            $motifs   = $request->request->all('motif');    // array assoc id => motif

            $countCree = 0;
            $countMaj = 0;

            foreach ($joueurs as $joueur) {
                $joueurId = $joueur->getId();
                $estPresent = isset($presents[$joueurId]);  // checkbox name="present[ID]"
                $motif = trim($motifs[$joueurId] ?? '');

                // Récupère la Presence existante OU en crée une nouvelle
                $presence = $presencesByJoueur[$joueurId] ?? null;
                if (!$presence) {
                    $presence = new Presence();
                    $presence->setJoueur($joueur);
                    $presence->setSeance($seance);
                    $presence->setSource(Presence::SOURCE_MANUEL);
                    $this->em->persist($presence);
                    $countCree++;
                } else {
                    $countMaj++;
                }

                $presence->setPresent($estPresent);
                $presence->setMotifAbsence(!$estPresent && $motif !== '' ? $motif : null);
            }

            $this->em->flush();

            // Gamification : recalcule les badges éligibles pour chaque joueuse pointée
            $nbBadges = $this->syncBadgesPourJoueurs($joueurs);

            $this->addFlash('success', sprintf(
                'Pointage enregistré : %d création(s), %d mise(s) à jour.%s',
                $countCree,
                $countMaj,
                $nbBadges > 0 ? sprintf(' 🏆 %d badge(s) débloqué(s) !', $nbBadges) : ''
            ));
            return $this->redirectToRoute('manager_seance_show', ['id' => $seance->getId()]);
        }

        return $this->render('manager/presence/pointage.html.twig', [
            'evenement'           => $seance,
            'type_evenement'      => 'seance',
            'titre_evenement'     => sprintf('Séance du %s', $seance->getDate()->format('d/m/Y H:i')),
            'equipe'              => $seance->getEquipe(),
            'joueurs'             => $joueurs,
            'presences_by_joueur' => $presencesByJoueur,
            'retour_url'          => $this->generateUrl('manager_seance_show', ['id' => $seance->getId()]),
            'csrf_id'             => 'pointage_seance_' . $seance->getId(),
        ]);
    }

    /**
     * Pointage des présences pour une rencontre.
     *
     * Pour les matchs : les "présences" sont les joueuses qui ont effectivement joué
     * (sur la feuille de match). Distinct des convocations (qui viennent AVANT le match).
     */
    #[Route('/rencontres/{id}/pointage', name: 'manager_presence_rencontre', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function pointageRencontre(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // Refus de pointage sur rencontre verrouillée (sauf admin)
        if ($rencontre->isVerrouillee()) {
            $this->denyAccessUnlessGranted(ClubVoter::CLUB_ADMIN, $rencontre);
            $this->addFlash('warning', 'Cette rencontre est verrouillée. Seul un dirigeant peut modifier le pointage.');
        }

        $joueurs = array_filter(
            $rencontre->getEquipe()->getJoueurs()->toArray(),
            fn($j) => $j->isActive()
        );
        usort($joueurs, fn($a, $b) => strcmp($a->getNom(), $b->getNom()));

        $presencesByJoueur = [];
        foreach ($rencontre->getPresences() as $p) {
            $presencesByJoueur[$p->getJoueur()->getId()] = $p;
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('pointage_rencontre_' . $rencontre->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
            }

            $presents = $request->request->all('present');
            $motifs   = $request->request->all('motif');

            $countCree = 0;
            $countMaj = 0;

            foreach ($joueurs as $joueur) {
                $joueurId = $joueur->getId();
                $estPresent = isset($presents[$joueurId]);
                $motif = trim($motifs[$joueurId] ?? '');

                $presence = $presencesByJoueur[$joueurId] ?? null;
                if (!$presence) {
                    $presence = new Presence();
                    $presence->setJoueur($joueur);
                    $presence->setRencontre($rencontre);
                    $presence->setSource(Presence::SOURCE_MANUEL);
                    $this->em->persist($presence);
                    $countCree++;
                } else {
                    $countMaj++;
                }

                $presence->setPresent($estPresent);
                $presence->setMotifAbsence(!$estPresent && $motif !== '' ? $motif : null);
            }

            $this->em->flush();

            // Gamification : sync badges après le pointage rencontre
            $nbBadges = $this->syncBadgesPourJoueurs($joueurs);

            $this->addFlash('success', sprintf(
                'Pointage enregistré : %d création(s), %d mise(s) à jour.%s',
                $countCree,
                $countMaj,
                $nbBadges > 0 ? sprintf(' 🏆 %d badge(s) débloqué(s) !', $nbBadges) : ''
            ));
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        return $this->render('manager/presence/pointage.html.twig', [
            'evenement'           => $rencontre,
            'type_evenement'      => 'rencontre',
            'titre_evenement'     => sprintf(
                '%s vs %s — %s',
                $rencontre->getEquipe()->getNom(),
                $rencontre->getAdversaire(),
                $rencontre->getDate()->format('d/m/Y H:i')
            ),
            'equipe'              => $rencontre->getEquipe(),
            'joueurs'             => $joueurs,
            'presences_by_joueur' => $presencesByJoueur,
            'retour_url'          => $this->generateUrl('manager_rencontre_show', ['id' => $rencontre->getId()]),
            'csrf_id'             => 'pointage_rencontre_' . $rencontre->getId(),
        ]);
    }
}
