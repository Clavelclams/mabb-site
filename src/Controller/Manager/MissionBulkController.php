<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Mission;
use App\Gamification\BadgeChecker;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [B32 12/06/2026] Assignation MULTIPLE de missions en 1 clic.
 *
 * Avant : staff devait ouvrir chaque fiche joueuse et ajouter une mission une par une.
 * Maintenant : 1 page, choix du type de mission, choix multi-joueuses, 1 clic = N missions.
 *
 * Gain de temps massif sur les événements récurrents (buvette tournoi, table de marque
 * journée, encadrement Génération Basket, etc.).
 */
class MissionBulkController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly JoueurRepository $joueurRepo,
        private readonly EntityManagerInterface $em,
        private readonly BadgeChecker $badgeChecker,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Page principale : sélecteur type mission + checkboxes joueuses.
     * GET/POST manager.mabb.fr/missions/bulk
     */
    #[Route('/missions/bulk', name: 'manager_mission_bulk', methods: ['GET', 'POST'])]
    public function bulk(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        // Liste des joueuses actives du club, ordonnée par nom
        $joueurs = $this->joueurRepo->createQueryBuilder('j')
            ->where('j.club = :c')
            ->andWhere('j.isActive = true')
            ->setParameter('c', $club)
            ->orderBy('j.nom', 'ASC')
            ->addOrderBy('j.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mission_bulk', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('manager_mission_bulk');
            }

            $type = (string) $request->request->get('type', '');
            if (!array_key_exists($type, Mission::TYPE_LIBELLES)) {
                $this->addFlash('error', 'Type de mission invalide.');
                return $this->redirectToRoute('manager_mission_bulk');
            }

            $dateStr = trim((string) $request->request->get('date', ''));
            $date = $dateStr !== ''
                ? \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr)
                : new \DateTimeImmutable();
            if ($date === false) $date = new \DateTimeImmutable();

            $description = trim((string) $request->request->get('description', '')) ?: null;
            $estBenevole = (bool) $request->request->get('est_benevole', true);

            $joueurIds = $request->request->all('joueur_ids');
            if (!is_array($joueurIds) || empty($joueurIds)) {
                $this->addFlash('warning', 'Aucune joueuse sélectionnée.');
                return $this->redirectToRoute('manager_mission_bulk');
            }

            /** @var User $staff */
            $staff = $this->getUser();
            $countCreated = 0;
            $countBadges = 0;

            foreach ($joueurIds as $joueurId) {
                $joueur = $this->joueurRepo->find((int) $joueurId);
                if ($joueur === null || $joueur->getClub()?->getId() !== $club->getId()) {
                    continue;
                }

                $mission = new Mission();
                $mission->setClub($club);
                $mission->setJoueur($joueur);
                $mission->setType($type);
                $mission->setDate($date);
                $mission->setDescription($description);
                $mission->setEstBenevole($estBenevole);
                $mission->setValidePar($staff);
                $this->em->persist($mission);
                $countCreated++;

                // BadgeChecker trigger (idempotent)
                $nouveaux = $this->badgeChecker->syncBadges($joueur);
                $countBadges += count($nouveaux);
            }

            $this->em->flush();

            $this->logger->info('Missions bulk créées', [
                'type'          => $type,
                'count'         => $countCreated,
                'badges_debloques' => $countBadges,
                'staff'         => $staff->getUserIdentifier(),
            ]);

            $this->addFlash('success', sprintf(
                '✅ %d mission(s) "%s" créée(s). %s',
                $countCreated,
                Mission::TYPE_LIBELLES[$type],
                $countBadges > 0 ? "🏆 $countBadges badge(s) débloqué(s)." : ''
            ));

            return $this->redirectToRoute('manager_mission_bulk');
        }

        return $this->render('manager/mission/bulk.html.twig', [
            'club'     => $club,
            'joueurs'  => $joueurs,
            'types'    => Mission::TYPE_LIBELLES,
        ]);
    }
}
