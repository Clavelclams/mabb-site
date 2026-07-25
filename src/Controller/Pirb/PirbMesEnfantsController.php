<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\ParentJoueur;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbMesEnfantsController — espace parent PIRB V1.4c.
 *
 * Le parent voit ses enfants liés (status active) + ses demandes en cours.
 * Peut faire une nouvelle demande de lien vers un Joueur du club.
 *
 * Sécurité : un parent ne peut pas voir d'autres enfants que les siens.
 */
class PirbMesEnfantsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParentJoueurRepository $parentRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * [SÉCU 26/07] Le(s) club(s) auxquels l'utilisateur a légitimement accès :
     * celui de sa propre fiche joueuse, et ceux de ses enfants déjà liés
     * (lien ACTIF uniquement — un lien en attente ne donne aucun droit).
     *
     * Sert à borner la recherche d'enfants : sans ça, n'importe quel compte
     * pouvait énumérer le fichier nominatif des mineures de TOUS les clubs.
     *
     * @return int[] IDs de clubs
     */
    private function clubsAutorises(User $user): array
    {
        $ids = [];

        $maFiche = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($maFiche?->getClub()?->getId() !== null) {
            $ids[] = (int) $maFiche->getClub()->getId();
        }

        foreach ($user->getUserClubRoles() as $ucr) {
            if ($ucr->isActive() && $ucr->isStatusActive() && $ucr->getClub()?->getId() !== null) {
                $ids[] = (int) $ucr->getClub()->getId();
            }
        }

        foreach ($this->parentRepo->findBy(['parentUser' => $user, 'statut' => ParentJoueur::STATUT_ACTIVE]) as $pj) {
            $clubEnfant = $pj->getJoueur()?->getClub()?->getId();
            if ($clubEnfant !== null) {
                $ids[] = (int) $clubEnfant;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Liste des enfants liés + demandes en cours du parent connecté.
     * GET pirb.mabb.fr/mes-enfants
     */
    #[Route('/mes-enfants', name: 'pirb_mes_enfants', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tousLiens = $this->parentRepo->createQueryBuilder('pj')
            ->where('pj.parentUser = :u')
            ->setParameter('u', $user)
            ->orderBy('pj.statut', 'ASC')
            ->getQuery()
            ->getResult();

        $enfants_actifs = [];
        $demandes_pending = [];
        foreach ($tousLiens as $pj) {
            /** @var ParentJoueur $pj */
            if ($pj->isActive()) {
                $enfants_actifs[] = $pj;
            } elseif ($pj->isPending()) {
                $demandes_pending[] = $pj;
            }
        }

        return $this->render('pirb/mes_enfants.html.twig', [
            'enfants_actifs'   => $enfants_actifs,
            'demandes_pending' => $demandes_pending,
        ]);
    }

    /**
     * Page de recherche + demande de lien vers un Joueur du club.
     * GET pirb.mabb.fr/mes-enfants/ajouter?q=...
     * POST pirb.mabb.fr/mes-enfants/ajouter   → crée la demande
     */
    #[Route('/mes-enfants/ajouter', name: 'pirb_mes_enfants_ajouter', methods: ['GET', 'POST'])]
    public function ajouter(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // [SÉCU 26/07] Périmètre de l'utilisateur : hors de ces clubs, il ne
        // voit rien et ne peut demander aucun rattachement.
        $clubsAutorises = $this->clubsAutorises($user);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_mes_enfants_ajouter', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('pirb_mes_enfants');
            }

            $joueurId = (int) $request->request->get('joueur_id', 0);
            $joueur = $this->joueurRepo->find($joueurId);
            // [SÉCU 26/07] Le contrôle d'appartenance manquait : on pouvait
            // poster l'id d'une mineure de n'importe quel club et créer une
            // demande de rattachement parental. Même réponse dans les deux cas
            // (introuvable / hors périmètre) pour ne pas confirmer l'existence.
            if ($joueur === null
                || $joueur->getClub()?->getId() === null
                || !in_array((int) $joueur->getClub()->getId(), $clubsAutorises, true)) {
                $this->logger->warning('Demande de lien parent refusée (hors périmètre)', [
                    'user_id'   => $user->getId(),
                    'joueur_id' => $joueurId,
                ]);
                $this->addFlash('error', 'Joueur introuvable.');
                return $this->redirectToRoute('pirb_mes_enfants_ajouter');
            }

            // Vérifie qu'on n'a pas déjà une demande active/pending pour ce joueur
            $existant = $this->parentRepo->findOneBy([
                'parentUser' => $user,
                'joueur'     => $joueur,
            ]);
            if ($existant !== null) {
                if ($existant->isActive()) {
                    $this->addFlash('info', sprintf('%s est déjà lié à ton compte.', $joueur->getPrenom()));
                } elseif ($existant->isPending()) {
                    $this->addFlash('info', sprintf('Ta demande pour %s est déjà en attente de validation.', $joueur->getPrenom()));
                } else {
                    $this->addFlash('error', sprintf('Une précédente demande pour %s a été refusée. Contacte le staff.', $joueur->getPrenom()));
                }
                return $this->redirectToRoute('pirb_mes_enfants');
            }

            $pj = new ParentJoueur();
            $pj->setParentUser($user);
            $pj->setJoueur($joueur);
            $pj->setStatut(ParentJoueur::STATUT_PENDING);
            $pj->setDemandePar(ParentJoueur::DEMANDE_PAR_PARENT);

            $this->em->persist($pj);
            $this->em->flush();

            $this->logger->info('Demande de lien parent-enfant créée', [
                'parent_user_id' => $user->getId(),
                'joueur_id'      => $joueur->getId(),
            ]);

            $this->addFlash('success', sprintf(
                'Demande envoyée pour %s ! Un membre du staff doit valider.',
                $joueur->getPrenom() . ' ' . $joueur->getNom()
            ));
            return $this->redirectToRoute('pirb_mes_enfants');
        }

        // GET — recherche
        // [SÉCU 26/07] AVANT : la requête n'avait AUCUN filtre club. Avec
        // quelques lettres (« ?q=a », « ?q=e »…), n'importe quel compte
        // reconstituait le fichier nominatif des mineures de tous les clubs
        // de la plateforme (nom, prénom, équipe donc tranche d'âge).
        // MAINTENANT : recherche bornée aux clubs de l'utilisateur, et
        // minimum 2 caractères pour éviter le balayage alphabétique.
        $recherche = trim((string) $request->query->get('q', ''));
        $joueurs = [];

        if (mb_strlen($recherche) >= 2 && $clubsAutorises !== []) {
            $r = '%' . strtolower($recherche) . '%';
            $joueurs = $this->joueurRepo->createQueryBuilder('j')
                ->where('LOWER(j.nom) LIKE :r OR LOWER(j.prenom) LIKE :r')
                ->andWhere('j.isActive = :a')
                ->andWhere('j.club IN (:clubs)')
                ->setParameter('r', $r)
                ->setParameter('a', true)
                ->setParameter('clubs', $clubsAutorises)
                ->setMaxResults(20)
                ->orderBy('j.nom', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('pirb/mes_enfants_ajouter.html.twig', [
            'recherche' => $recherche,
            'joueurs'   => $joueurs,
        ]);
    }
}
