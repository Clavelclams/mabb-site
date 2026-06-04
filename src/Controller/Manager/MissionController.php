<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Mission;
use App\Gamification\BadgeChecker;
use App\Gamification\XpCalculator;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MissionController — saisie manuelle de missions bénévolat par staff/admin.
 *
 * Permet au DIRIGEANT ou STAFF d'ajouter à la main une mission de gamification
 * à un joueur — utile quand l'action n'a pas été tracée via Evenement ou
 * RencontreRole (ex: "T'as aidé à ranger le matériel", "Tu as parrainé X",
 * "Tu as fait un don"…).
 *
 * Workflow :
 *   1. Sur la fiche joueur → bouton "Ajouter une mission" visible pour STAFF
 *   2. Form simple : type Mission + date + description
 *   3. Submit → crée Mission + appelle BadgeChecker::syncBadges
 *   4. Flash success avec XP gagnés + badges débloqués
 *
 * Sécurité : double vérification — la Mission ciblée est dans le club du joueur,
 * et le user créateur est CLUB_STAFF du même club (via ClubVoter sur Joueur).
 */
class MissionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BadgeChecker $badgeChecker,
        private readonly XpCalculator $xpCalculator,
    ) {}

    #[Route('/joueuses/{id}/missions/nouvelle', name: 'manager_mission_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function nouvelle(Request $request, Joueur $joueur): Response
    {
        // Le ClubVoter vérifie que l'user courant est STAFF dans le club du joueur.
        // Empêche un coach du club A de créer des missions pour un joueur du club B.
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        $errors = [];

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('new_mission_' . $joueur->getId(), $token)) {
                $errors[] = 'Jeton de sécurité invalide.';
            }

            $type        = (string) $request->request->get('type', '');
            $dateStr     = (string) $request->request->get('date', '');
            $description = trim((string) $request->request->get('description', '')) ?: null;
            // Checkbox "dans le cadre du poste" : par défaut décochée = action bénévole.
            // Cochée = mission rémunérée → XP ira en axe D (performance employé) au lieu de C (bénévolat).
            $estBenevole = !$request->request->getBoolean('dans_cadre_poste');

            if (!in_array($type, Mission::TYPES, true)) {
                $errors[] = 'Type de mission invalide.';
            }
            if ($dateStr === '') {
                $errors[] = 'La date est obligatoire.';
            }
            $date = null;
            if ($dateStr !== '') {
                try {
                    $date = new \DateTimeImmutable($dateStr);
                } catch (\Exception) {
                    $errors[] = 'Date invalide.';
                }
            }

            if (empty($errors) && $date !== null) {
                $mission = new Mission();
                $mission->setClub($joueur->getClub());
                $mission->setJoueur($joueur);
                $mission->setType($type);
                $mission->setDate($date);
                $mission->setDescription($description);
                $mission->setEstBenevole($estBenevole);
                $mission->setValidePar($this->getUser() instanceof User ? $this->getUser() : null);
                $this->em->persist($mission);
                $this->em->flush();

                // Recalcule l'XP + badges après ajout pour feedback immédiat
                $nouveauxBadges = $this->badgeChecker->syncBadges($joueur);
                $xpDetails      = $this->xpCalculator->detailsSaison($joueur);

                $this->addFlash('success', sprintf(
                    'Mission "%s" ajoutée pour %s %s. %s%s',
                    Mission::TYPE_LIBELLES[$type] ?? $type,
                    $joueur->getPrenom(),
                    $joueur->getNom(),
                    sprintf('🎯 XP saison actuel : %d.', $xpDetails['xp_total']),
                    count($nouveauxBadges) > 0
                        ? sprintf(' 🏆 %d nouveau(x) badge(s) débloqué(s) !', count($nouveauxBadges))
                        : ''
                ));
                return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
            }
        }

        return $this->render('manager/mission/new.html.twig', [
            'joueur'        => $joueur,
            'errors'        => $errors,
            'types'         => Mission::TYPES,
            'types_libelles' => Mission::TYPE_LIBELLES,
            'date_defaut'   => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);
    }
}
