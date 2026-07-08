<?php

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\Evenement;
use App\Entity\Sport\EvenementParticipation;
use App\Entity\Sport\InscriptionSortie;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Mission;
use App\Gamification\BadgeChecker;
use App\Repository\Sport\EvenementParticipationRepository;
use App\Repository\Sport\EvenementRepository;
use App\Repository\Sport\InscriptionSortieRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\RencontreRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EvenementController — toutes les actions sur les événements club.
 *
 * Routes :
 *   - /evenements                         → index agrégé (événements + rencontres à venir)
 *   - /evenements/nouveau                 → création (CLUB_STAFF)
 *   - /evenements/{id}                    → détail + liste participants
 *   - /evenements/{id}/modifier           → édition (CLUB_STAFF)
 *   - /evenements/{id}/publier            → bascule brouillon → publié (CLUB_STAFF)
 *   - /evenements/{id}/annuler            → bascule en annulé (CLUB_STAFF)
 *   - /evenements/{id}/participer         → inscription User (CLUB_MEMBER)
 *   - /evenements/{id}/desinscrire        → annulation inscription User
 *   - /evenements/{id}/presence/{userId}  → marquer présent (CLUB_STAFF) — trigger gamification
 *
 * Gamification : quand le staff marque un participant "présent" via
 * /evenements/{id}/presence/{userId}, une Mission est auto-créée pour le
 * Joueur lié au User (si existant). Cela alimente l'XP + les badges Axe C.
 */
class EvenementController extends AbstractController
{
    /**
     * Mapping type Evenement → type Mission (axe C bénévolat).
     * Si le type d'événement n'est pas dans cette map, la Mission utilise TYPE_AUTRE.
     */
    private const MAPPING_TYPE_MISSION = [
        Evenement::TYPE_REUNION         => Mission::TYPE_EVENEMENT,
        Evenement::TYPE_AG              => Mission::TYPE_AG,
        Evenement::TYPE_TOURNOI_INTERNE => Mission::TYPE_EVENEMENT,
        Evenement::TYPE_SORTIE          => Mission::TYPE_EVENEMENT,
        Evenement::TYPE_FORMATION       => Mission::TYPE_FORMATION,
        Evenement::TYPE_FETE            => Mission::TYPE_EVENEMENT,
        Evenement::TYPE_AUTRE           => Mission::TYPE_AUTRE,
    ];

    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EvenementRepository $evenementRepository,
        private readonly EvenementParticipationRepository $participationRepository,
        private readonly RencontreRepository $rencontreRepository,
        private readonly InscriptionSortieRepository $inscriptionRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly BadgeChecker $badgeChecker,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Index agrégé : événements + rencontres à venir + passées récentes.
     */
    #[Route('/evenements', name: 'manager_evenement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        $isStaff = $this->isGranted(ClubVoter::CLUB_STAFF, $club);

        // Staff voit tous les statuts ; membre voit seulement les PUBLIE
        $statutsVisibles = $isStaff
            ? [Evenement::STATUT_BROUILLON, Evenement::STATUT_PUBLIE, Evenement::STATUT_ANNULE]
            : [Evenement::STATUT_PUBLIE];

        $evenementsFuturs = $this->evenementRepository->avenirParClub($club, $statutsVisibles, 30);
        $evenementsPasses = $this->evenementRepository->passesParClub($club, $statutsVisibles, 20);

        // Rencontres à venir du club (toute équipe confondue)
        $rencontresFuturs = $this->rencontreRepository->createQueryBuilder('r')
            ->andWhere('r.club = :c')->setParameter('c', $club)
            ->andWhere('r.date >= :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.date', 'ASC')
            ->setMaxResults(20)
            ->getQuery()->getResult();

        return $this->render('manager/evenement/index.html.twig', [
            'club'              => $club,
            'evenements_futurs' => $evenementsFuturs,
            'evenements_passes' => $evenementsPasses,
            'rencontres_futurs' => $rencontresFuturs,
            'is_staff'          => $isStaff,
        ]);
    }

    #[Route('/evenements/nouveau', name: 'manager_evenement_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $evenement = new Evenement();
        $evenement->setClub($club);
        $evenement->setCreateur($this->getUser() instanceof User ? $this->getUser() : null);

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('new_evenement', $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_evenement_new');
            }

            $this->hydraterDepuisRequete($evenement, $request);
            $this->em->persist($evenement);
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Événement "%s" créé en %s. %s',
                $evenement->getTitre(),
                $evenement->getStatut() === Evenement::STATUT_PUBLIE ? 'public' : 'brouillon',
                $evenement->getStatut() === Evenement::STATUT_BROUILLON
                    ? '(Pense à le publier pour le rendre visible aux membres.)'
                    : ''
            ));
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        return $this->render('manager/evenement/edit.html.twig', [
            'evenement' => $evenement,
            'mode'      => 'nouveau',
        ]);
    }

    #[Route('/evenements/{id}', name: 'manager_evenement_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $evenement);

        $isStaff = $this->isGranted(ClubVoter::CLUB_STAFF, $evenement);

        // Les membres ne voient pas les brouillons
        if (!$isStaff && !$evenement->isPublie() && !$evenement->isAnnule()) {
            $this->addFlash('warning', 'Cet événement n\'est pas encore publié.');
            return $this->redirectToRoute('manager_evenement_index');
        }

        // Participation du user courant à cet événement (pour bouton "Je participe")
        $maParticipation = null;
        if ($this->getUser() instanceof User) {
            $maParticipation = $this->participationRepository->trouverPour($this->getUser(), $evenement);
        }

        // Inscriptions Sortie + agrégats + joueurs du club (staff uniquement,
        // ces données contiennent des infos de mineurs → jamais côté membre).
        $inscriptions = [];
        $agregats     = null;
        $joueursClub  = [];
        if ($isStaff) {
            $inscriptions = $this->inscriptionRepository->findByEvenement($evenement);
            $agregats     = $this->agregatsInscriptions($inscriptions);
            $joueursClub  = $this->joueurRepository->findByClub($evenement->getClub()->getId());
        }

        return $this->render('manager/evenement/show.html.twig', [
            'evenement'        => $evenement,
            'is_staff'         => $isStaff,
            'ma_participation' => $maParticipation,
            'participations'   => $evenement->getParticipations(),
            'inscriptions'     => $inscriptions,
            'agregats'         => $agregats,
            'joueurs_club'     => $joueursClub,
        ]);
    }

    // =====================================================================
    // SORTIES — inscriptions (STAFF). Doc 23 / ADR-0011. Données de mineurs :
    // toutes ces routes exigent CLUB_STAFF + CSRF, jamais exposées aux membres.
    // =====================================================================

    #[Route('/evenements/{id}/inscriptions', name: 'manager_evenement_inscription_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouterInscription(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        if (!$this->isCsrfTokenValid('inscription_add_' . $evenement->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $inscription = new InscriptionSortie();
        $inscription->setEvenement($evenement);
        $inscription->setCreatedBy($this->getUser() instanceof User ? $this->getUser() : null);

        // Licenciée (joueur) OU saisie libre selon l'ouverture (doc 23 §3.1).
        $joueurId = (int) $request->request->get('joueur_id', 0);
        if ($joueurId > 0) {
            $joueur = $this->joueurRepository->find($joueurId);
            // Isolation multi-tenant : la joueuse doit être du club de l'événement.
            if (!$joueur instanceof Joueur || $joueur->getClub()?->getId() !== $evenement->getClub()?->getId()) {
                $this->addFlash('error', 'Joueuse invalide.');
                return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
            }
            $inscription->setJoueur($joueur);
        } elseif ($evenement->getOuvertA() === Evenement::OUVERT_TOUS) {
            $nom    = trim((string) $request->request->get('nom', ''));
            $prenom = trim((string) $request->request->get('prenom', ''));
            if ($nom === '' || $prenom === '') {
                $this->addFlash('error', 'Nom et prénom obligatoires pour un participant non licencié.');
                return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
            }
            $inscription->setNom($nom);
            $inscription->setPrenom($prenom);
            $dn = trim((string) $request->request->get('date_naissance', ''));
            if ($dn !== '') {
                try { $inscription->setDateNaissance(new \DateTimeImmutable($dn)); } catch (\Exception) {}
            }
            $inscription->setResponsableLegal(trim((string) $request->request->get('responsable_legal', '')) ?: null);
            $inscription->setTelephoneContact(trim((string) $request->request->get('telephone_contact', '')) ?: null);
        } else {
            $this->addFlash('error', 'Événement réservé aux licenciés : choisis une joueuse.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        // Cohérences dérivées (doc 23 §4.3).
        $inscription->setAutorisationStatut(
            $evenement->isAutorisationRequise()
                ? InscriptionSortie::AUTORISATION_EN_ATTENTE
                : InscriptionSortie::AUTORISATION_NON_REQUISE
        );
        $inscription->setPaiementStatut(
            $evenement->isEstPayant()
                ? InscriptionSortie::PAIEMENT_A_PAYER
                : InscriptionSortie::PAIEMENT_GRATUIT
        );

        $this->em->persist($inscription);
        $this->em->flush();
        $this->addFlash('success', sprintf('%s inscrit·e.', $inscription->getNomAffichage()));
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    #[Route('/evenements/{id}/inscriptions/{iid}/autorisation', name: 'manager_evenement_inscription_autorisation', methods: ['POST'], requirements: ['id' => '\d+', 'iid' => '\d+'])]
    public function basculerAutorisation(Request $request, Evenement $evenement, int $iid): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $inscription = $this->chargerInscription($evenement, $iid, $request, 'autorisation');
        if (!$inscription instanceof InscriptionSortie) { return $inscription; }

        // Bascule EN_ATTENTE ↔ RECUE (n'agit pas sur NON_REQUISE).
        if ($inscription->getAutorisationStatut() === InscriptionSortie::AUTORISATION_RECUE) {
            $inscription->setAutorisationStatut(InscriptionSortie::AUTORISATION_EN_ATTENTE);
        } elseif ($inscription->getAutorisationStatut() === InscriptionSortie::AUTORISATION_EN_ATTENTE) {
            $inscription->setAutorisationStatut(InscriptionSortie::AUTORISATION_RECUE);
        }
        $this->em->flush();
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    #[Route('/evenements/{id}/inscriptions/{iid}/paiement', name: 'manager_evenement_inscription_paiement', methods: ['POST'], requirements: ['id' => '\d+', 'iid' => '\d+'])]
    public function enregistrerPaiement(Request $request, Evenement $evenement, int $iid): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $inscription = $this->chargerInscription($evenement, $iid, $request, 'paiement');
        if (!$inscription instanceof InscriptionSortie) { return $inscription; }

        $statut = (string) $request->request->get('paiement_statut', '');
        if (in_array($statut, InscriptionSortie::PAIEMENT_STATUTS, true)) {
            $inscription->setPaiementStatut($statut);
        }
        $montant = trim((string) $request->request->get('montant_paye', ''));
        $inscription->setMontantPaye($montant !== '' ? $montant : null);
        $moyen = (string) $request->request->get('moyen_paiement', '');
        $inscription->setMoyenPaiement(in_array($moyen, InscriptionSortie::MOYENS_PAIEMENT, true) ? $moyen : null);
        $date = trim((string) $request->request->get('paiement_date', ''));
        if ($date !== '') {
            try { $inscription->setPaiementDate(new \DateTimeImmutable($date)); } catch (\Exception) {}
        }
        $this->em->flush();
        $this->addFlash('success', 'Paiement mis à jour.');
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    #[Route('/evenements/{id}/inscriptions/{iid}/presence', name: 'manager_evenement_inscription_presence', methods: ['POST'], requirements: ['id' => '\d+', 'iid' => '\d+'])]
    public function changerPresence(Request $request, Evenement $evenement, int $iid): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $inscription = $this->chargerInscription($evenement, $iid, $request, 'presence');
        if (!$inscription instanceof InscriptionSortie) { return $inscription; }

        $presence = (string) $request->request->get('presence', '');
        if (in_array($presence, InscriptionSortie::PRESENCES, true)) {
            $inscription->setPresence($presence);
            $this->em->flush();
        }
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    #[Route('/evenements/{id}/inscriptions/{iid}/supprimer', name: 'manager_evenement_inscription_supprimer', methods: ['POST'], requirements: ['id' => '\d+', 'iid' => '\d+'])]
    public function supprimerInscription(Request $request, Evenement $evenement, int $iid): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $inscription = $this->chargerInscription($evenement, $iid, $request, 'supprimer');
        if (!$inscription instanceof InscriptionSortie) { return $inscription; }

        $this->em->remove($inscription);
        $this->em->flush();
        $this->addFlash('success', 'Inscription retirée.');
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    /**
     * Charge une inscription en vérifiant CSRF + appartenance à l'événement +
     * droits (CLUB_STAFF sur l'inscription, isolée par club via ClubAwareInterface).
     * Retourne l'inscription, ou une Response (redirect) si le CSRF est invalide.
     */
    private function chargerInscription(Evenement $evenement, int $iid, Request $request, string $intention): InscriptionSortie|Response
    {
        if (!$this->isCsrfTokenValid('inscription_' . $intention . '_' . $iid, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }
        $inscription = $this->inscriptionRepository->find($iid);
        if (!$inscription instanceof InscriptionSortie || $inscription->getEvenement()?->getId() !== $evenement->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $inscription);
        return $inscription;
    }

    /**
     * Agrégats du dashboard d'une sortie (doc 23 §6.1).
     *
     * @param InscriptionSortie[] $inscriptions
     * @return array<string, int|float>
     */
    private function agregatsInscriptions(array $inscriptions): array
    {
        $a = [
            'nb' => count($inscriptions),
            'autorisations_recues' => 0,
            'autorisations_manquantes' => 0,
            'payes' => 0,
            'a_payer' => 0,
            'total_encaisse' => 0.0,
            'presents' => 0,
        ];
        foreach ($inscriptions as $i) {
            if ($i->getAutorisationStatut() === InscriptionSortie::AUTORISATION_RECUE)   { $a['autorisations_recues']++; }
            if ($i->getAutorisationStatut() === InscriptionSortie::AUTORISATION_EN_ATTENTE) { $a['autorisations_manquantes']++; }
            if ($i->getPaiementStatut() === InscriptionSortie::PAIEMENT_PAYE) {
                $a['payes']++;
                $a['total_encaisse'] += (float) ($i->getMontantPaye() ?? 0);
            }
            if ($i->getPaiementStatut() === InscriptionSortie::PAIEMENT_A_PAYER) { $a['a_payer']++; }
            if ($i->getPresence() === InscriptionSortie::PRESENCE_PRESENT) { $a['presents']++; }
        }
        return $a;
    }

    #[Route('/evenements/{id}/modifier', name: 'manager_evenement_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('edit_evenement_' . $evenement->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_evenement_edit', ['id' => $evenement->getId()]);
            }

            $this->hydraterDepuisRequete($evenement, $request);
            $this->em->flush();

            $this->addFlash('success', 'Événement mis à jour.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        return $this->render('manager/evenement/edit.html.twig', [
            'evenement' => $evenement,
            'mode'      => 'modifier',
        ]);
    }

    #[Route('/evenements/{id}/publier', name: 'manager_evenement_publier', methods: ['POST'])]
    public function publier(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('publier_' . $evenement->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }
        $evenement->setStatut(Evenement::STATUT_PUBLIE);
        $this->em->flush();
        $this->addFlash('success', 'Événement publié.');
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    #[Route('/evenements/{id}/annuler', name: 'manager_evenement_annuler', methods: ['POST'])]
    public function annuler(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('annuler_' . $evenement->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }
        $evenement->setStatut(Evenement::STATUT_ANNULE);
        $this->em->flush();
        $this->addFlash('warning', 'Événement annulé.');
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    /**
     * Inscription d'un user à un événement. Initiative du user lui-même.
     */
    #[Route('/evenements/{id}/participer', name: 'manager_evenement_participer', methods: ['POST'])]
    public function participer(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $evenement);
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('error', 'Connexion requise.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('participer_' . $evenement->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        if (!$evenement->isPublie()) {
            $this->addFlash('error', 'Inscriptions fermées (événement non publié ou annulé).');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }
        if ($evenement->isComplet()) {
            $this->addFlash('warning', 'Événement complet.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $existante = $this->participationRepository->trouverPour($user, $evenement);
        if ($existante) {
            $this->addFlash('info', 'Tu es déjà inscrit à cet événement.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $p = new EvenementParticipation();
        $p->setEvenement($evenement);
        $p->setUser($user);
        $p->setStatut(EvenementParticipation::STATUT_INSCRIT);
        $this->em->persist($p);
        $this->em->flush();

        $this->addFlash('success', 'Inscription confirmée !');
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    /**
     * Désinscription d'un user d'un événement (initiative du user lui-même).
     */
    #[Route('/evenements/{id}/desinscrire', name: 'manager_evenement_desinscrire', methods: ['POST'])]
    public function desinscrire(Request $request, Evenement $evenement): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $evenement);
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('desinscrire_' . $evenement->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $p = $this->participationRepository->trouverPour($user, $evenement);
        if ($p) {
            $this->em->remove($p);
            $this->em->flush();
            $this->addFlash('success', 'Désinscription effectuée.');
        }
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    /**
     * Le staff marque un participant comme présent après l'événement.
     * Si le user a un Joueur lié, on crée AUTOMATIQUEMENT une Mission de
     * gamification → alimente l'XP + les badges Axe C bénévolat.
     */
    #[Route('/evenements/{id}/presence/{userId}', name: 'manager_evenement_marquer_present', methods: ['POST'], requirements: ['id' => '\d+', 'userId' => '\d+'])]
    public function marquerPresent(
        Request $request,
        Evenement $evenement,
        int $userId,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $evenement);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('presence_evenement_' . $evenement->getId(), $token)) {
            $this->addFlash('error', 'Jeton invalide.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        // Trouver la participation
        $p = null;
        foreach ($evenement->getParticipations() as $part) {
            if ($part->getUser() && $part->getUser()->getId() === $userId) {
                $p = $part;
                break;
            }
        }
        if (!$p) {
            $this->addFlash('error', 'Participation introuvable.');
            return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
        }

        $nouveauStatut = (string) $request->request->get('statut', EvenementParticipation::STATUT_PRESENT);
        if (!in_array($nouveauStatut, EvenementParticipation::STATUTS, true)) {
            $nouveauStatut = EvenementParticipation::STATUT_PRESENT;
        }

        $ancienStatut = $p->getStatut();
        $p->setStatut($nouveauStatut);

        // Si on passe en PRESENT et qu'on n'y était pas avant : créer la Mission gamification
        $missionsCreees = 0;
        if ($nouveauStatut === EvenementParticipation::STATUT_PRESENT
            && $ancienStatut !== EvenementParticipation::STATUT_PRESENT) {

            $joueur = $this->trouverJoueurDuUser($p->getUser(), $evenement->getClub()->getId());
            if ($joueur !== null) {
                $mission = new Mission();
                $mission->setClub($evenement->getClub());
                $mission->setJoueur($joueur);
                $mission->setType(self::MAPPING_TYPE_MISSION[$evenement->getType()] ?? Mission::TYPE_AUTRE);
                $mission->setDate(\DateTimeImmutable::createFromInterface($evenement->getDate()));
                $mission->setDescription(sprintf(
                    'Participation à "%s" (%s)',
                    $evenement->getTitre(),
                    $evenement->getTypeLibelle()
                ));
                $mission->setValidePar($this->getUser() instanceof User ? $this->getUser() : null);
                $this->em->persist($mission);
                $missionsCreees++;
            }
        }

        $this->em->flush();

        // Sync badges : si une Mission vient d'être créée, le BadgeChecker peut
        // débloquer les badges axe C (C_FIRST_MISSION, C_BENEVOLE_5, etc.)
        $nbBadges = 0;
        if ($missionsCreees > 0) {
            $joueur = $this->trouverJoueurDuUser($p->getUser(), $evenement->getClub()->getId());
            if ($joueur !== null) {
                $nouveaux = $this->badgeChecker->syncBadges($joueur);
                $nbBadges = count($nouveaux);
            }
        }

        $this->addFlash('success', sprintf(
            'Statut mis à jour : %s.%s%s',
            $nouveauStatut,
            $missionsCreees > 0 ? ' 🎯 Mission gamification créée.' : '',
            $nbBadges > 0 ? sprintf(' 🏆 %d badge(s) débloqué(s) !', $nbBadges) : ''
        ));
        return $this->redirectToRoute('manager_evenement_show', ['id' => $evenement->getId()]);
    }

    // ====================================================================
    // Helpers privés
    // ====================================================================

    /**
     * Hydrate l'événement depuis les champs du formulaire.
     * Centralisé ici car même mapping pour création et édition.
     */
    private function hydraterDepuisRequete(Evenement $evenement, Request $request): void
    {
        $evenement->setTitre(trim((string) $request->request->get('titre', '')));
        $evenement->setDescription(trim((string) $request->request->get('description', '')) ?: null);
        $evenement->setType((string) $request->request->get('type', Evenement::TYPE_AUTRE));
        $evenement->setStatut((string) $request->request->get('statut', Evenement::STATUT_BROUILLON));
        $evenement->setOuvertA((string) $request->request->get('ouvert_a', Evenement::OUVERT_TOUS));
        $evenement->setLieu(trim((string) $request->request->get('lieu', '')) ?: null);

        $dateStr = (string) $request->request->get('date', '');
        if ($dateStr !== '') {
            $evenement->setDate(new \DateTimeImmutable($dateStr));
        }
        $dateFinStr = (string) $request->request->get('date_fin', '');
        $evenement->setDateFin($dateFinStr !== '' ? new \DateTimeImmutable($dateFinStr) : null);

        $maxStr = trim((string) $request->request->get('inscriptions_max', ''));
        $evenement->setInscriptionsMax($maxStr !== '' ? (int) $maxStr : null);

        // Sorties payantes (doc 23) : payant + prix, autorisation parentale.
        $evenement->setEstPayant($request->request->getBoolean('est_payant'));
        $prixStr = trim((string) $request->request->get('prix', ''));
        $evenement->setPrix($evenement->isEstPayant() && $prixStr !== '' ? $prixStr : null);
        $evenement->setAutorisationRequise($request->request->getBoolean('autorisation_requise'));
    }

    /**
     * Trouve le Joueur lié à un User dans un club donné (pour création Mission).
     * Retourne null si le User n'est pas joueur (cas bénévole pur, parent, etc.).
     */
    private function trouverJoueurDuUser(User $user, int $clubId): ?\App\Entity\Sport\Joueur
    {
        return $this->em->getRepository(\App\Entity\Sport\Joueur::class)->findOneBy([
            'user' => $user,
            'club' => $clubId,
        ]);
    }
}
