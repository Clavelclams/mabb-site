<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\BilanCompetence;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ManagerBilanController — Gestion des bilans de compétences (côté coach/staff).
 *
 * Routes :
 *   GET  /bilans                    → liste tous les bilans du club
 *   GET  /bilans/new/{joueur_id}    → formulaire création pour une joueuse
 *   POST /bilans/new/{joueur_id}    → traitement création
 *   GET  /bilans/{id}               → affichage lecture seule
 *   GET  /bilans/{id}/edit          → formulaire édition
 *   POST /bilans/{id}/edit          → traitement édition
 *   POST /bilans/{id}/valider       → passer BROUILLON → VALIDE
 *
 * Sécurité : CLUB_STAFF minimum (coaches inclus).
 * Multi-tenant : club_id + vérification que la joueuse appartient au club.
 */
#[Route('/bilans', name: 'manager_bilan_')]
class ManagerBilanController extends AbstractController
{
    public function __construct(
        private readonly BilanCompetenceRepository $bilanRepo,
        private readonly JoueurRepository          $joueurRepo,
        private readonly TenantResolver            $tenantResolver,
        private readonly EntityManagerInterface    $em,
        private readonly \App\Service\SaisonService $saisonService,
    ) {}

    // =========================================================================
    // ① Liste des bilans du club
    // =========================================================================

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            $this->addFlash('danger', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        $saison = $request->query->get('saison', $this->saisonCourante());

        $bilans  = $this->bilanRepo->findByClubAndSaison($club, $saison);
        $saisonsRaw = $this->bilanRepo->createQueryBuilder('b')
            ->select('DISTINCT b.saison')
            ->where('b.club = :club')
            ->setParameter('club', $club)
            ->orderBy('b.saison', 'DESC')
            ->getQuery()
            ->getScalarResult();
        $saisons = array_column($saisonsRaw, 'saison');

        return $this->render('manager/bilan/index.html.twig', [
            'bilans'         => $bilans,
            'saison_courante'=> $saison,
            'saisons'        => $saisons,
        ]);
    }

    // =========================================================================
    // ② Créer un bilan pour une joueuse
    // =========================================================================

    #[Route('/new/{id}', name: 'new', methods: ['GET', 'POST'])]
    public function nouveau(Joueur $joueur, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        // Multi-tenant : la joueuse doit appartenir au même club
        if ($joueur->getClub()?->getId() !== $club->getId()) {
            throw $this->createAccessDeniedException();
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $bilan = new BilanCompetence();
            $bilan->setJoueur($joueur)
                  ->setCoach($user)
                  ->setClub($club)
                  ->setSaison($this->saisonCourante());

            $this->hydrateFromRequest($bilan, $request);

            if (!$this->isCsrfTokenValid('bilan_' . $joueur->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF invalide.');
                return $this->redirectToRoute('manager_bilan_new', ['id' => $joueur->getId()]);
            }

            $this->em->persist($bilan);
            $this->em->flush();

            $this->addFlash('success', sprintf(
                '📋 Bilan créé pour %s (%s).',
                $joueur->getNomComplet(),
                $bilan->getStatut() === BilanCompetence::STATUT_VALIDE ? 'validé' : 'brouillon'
            ));

            return $this->redirectToRoute('manager_bilan_show', ['id' => $bilan->getId()]);
        }

        // Pre-fill depuis la fiche joueur si disponible
        $bilan = new BilanCompetence();
        $bilan->setJoueur($joueur)
              ->setCoach($user)
              ->setClub($club)
              ->setSaison($this->saisonCourante())
              // [FIX 05/07/2026] getLicenceNumero() n'existe pas sur Joueur
              // (la propriété s'appelle `licence`) → fatal error, page 500
              // au clic sur "Créer bilan". C'était LE bug.
              ->setNumeroLicence($joueur->getLicence())
              ->setDateEvaluation(new \DateTimeImmutable());

        return $this->render('manager/bilan/edit.html.twig', [
            'bilan'   => $bilan,
            'joueur'  => $joueur,
            'mode'    => 'new',
            'csrf_id' => 'bilan_' . $joueur->getId(),
        ]);
    }

    // =========================================================================
    // ③ Afficher un bilan (lecture seule)
    // =========================================================================

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(BilanCompetence $bilan): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($bilan->getClub()?->getId() !== $club?->getId()) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        return $this->render('manager/bilan/show.html.twig', ['bilan' => $bilan]);
    }

    // =========================================================================
    // ④ Éditer un bilan
    // =========================================================================

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(BilanCompetence $bilan, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($bilan->getClub()?->getId() !== $club?->getId()) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('bilan_edit_' . $bilan->getId(), $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token CSRF invalide.');
                return $this->redirectToRoute('manager_bilan_edit', ['id' => $bilan->getId()]);
            }

            $this->hydrateFromRequest($bilan, $request);
            // updatedAt est mis à jour automatiquement par le lifecycle #[ORM\PreUpdate]
            $this->em->flush();

            $this->addFlash('success', '✅ Bilan mis à jour.');
            return $this->redirectToRoute('manager_bilan_show', ['id' => $bilan->getId()]);
        }

        return $this->render('manager/bilan/edit.html.twig', [
            'bilan'   => $bilan,
            'joueur'  => $bilan->getJoueur(),
            'mode'    => 'edit',
            'csrf_id' => 'bilan_edit_' . $bilan->getId(),
        ]);
    }

    // =========================================================================
    // ⑤ Valider un bilan (BROUILLON → VALIDE, joueuse peut voir depuis PIRB)
    // =========================================================================

    #[Route('/{id}/valider', name: 'valider', methods: ['POST'])]
    public function valider(BilanCompetence $bilan, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($bilan->getClub()?->getId() !== $club?->getId()) {
            throw $this->createAccessDeniedException();
        }
        $this->denyAccessUnlessGranted('CLUB_STAFF', $club);

        if (!$this->isCsrfTokenValid('bilan_valider_' . $bilan->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('manager_bilan_show', ['id' => $bilan->getId()]);
        }

        $bilan->valider();
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '✅ Bilan de %s validé — maintenant visible depuis PIRB.',
            $bilan->getJoueur()?->getNomComplet() ?? '?'
        ));

        return $this->redirectToRoute('manager_bilan_show', ['id' => $bilan->getId()]);
    }

    // =========================================================================
    // Helpers privés
    // =========================================================================

    /**
     * Hydrate l'entité BilanCompetence depuis le POST du formulaire.
     * Les champs de score sont castés en int|null (vide → null).
     */
    private function hydrateFromRequest(BilanCompetence $b, Request $req): void
    {
        $p = $req->request;

        // Métadonnées
        $b->setContexte($p->get('contexte') ?: null);
        $b->setSaison($p->get('saison', $this->saisonCourante()));
        $dateStr = $p->get('date_evaluation');
        $b->setDateEvaluation($dateStr ? new \DateTimeImmutable($dateStr) : null);
        $b->setStatut($p->get('statut') === BilanCompetence::STATUT_VALIDE
            ? BilanCompetence::STATUT_VALIDE
            : BilanCompetence::STATUT_BROUILLON);

        // Renseignements
        $b->setNumeroLicence($p->get('numero_licence') ?: null);
        $b->setNumSecuSociale($p->get('num_secu_sociale') ?: null);
        $b->setMutuelle($p->get('mutuelle') ?: null);
        $b->setProblemeSante($p->get('probleme_sante') ?: null);
        $b->setAllergies($p->get('allergies') ?: null);
        $b->setRegimeAlimentaire($p->get('regime_alimentaire') ?: null);

        // Sidebar
        $b->setNbSeances($this->nullInt($p->get('nb_seances')));
        $b->setPresenceType($p->get('presence_type') ?: null);
        $b->setTaille($this->nullInt($p->get('taille')));
        $b->setPoids($p->get('poids') ?: null);
        $b->setEnvergure($this->nullInt($p->get('envergure')));
        $b->setTailleAssise($this->nullInt($p->get('taille_assise')));
        $b->setPointure($this->nullInt($p->get('pointure')));
        $b->setMainForte($p->get('main_forte') ?: null);
        $b->setProfilDeJeu($p->get('profil_de_jeu') ?: null);

        // Scores — Vie quotidienne
        $b->setVqRespectRegles($this->nullInt($p->get('vq_respect_regles')));
        $b->setVqPonctualite($this->nullInt($p->get('vq_ponctualite')));
        $b->setVqDiscipline($this->nullInt($p->get('vq_discipline')));
        $b->setVqVieGroupe($this->nullInt($p->get('vq_vie_groupe')));
        $b->setVqRangement($this->nullInt($p->get('vq_rangement')));

        // Qualités mentales
        $b->setQmEnthousiasme($this->nullInt($p->get('qm_enthousiasme')));
        $b->setQmDetermination($this->nullInt($p->get('qm_determination')));
        $b->setQmConfiance($this->nullInt($p->get('qm_confiance')));
        $b->setQmCuriosite($this->nullInt($p->get('qm_curiosite')));
        $b->setQmAutonomie($this->nullInt($p->get('qm_autonomie')));
        $b->setQmConcentration($this->nullInt($p->get('qm_concentration')));

        // Qualités technico-tactiques
        $b->setQttAdresse($this->nullInt($p->get('qtt_adresse')));
        $b->setQttEfficacitePanier($this->nullInt($p->get('qtt_efficacite_panier')));
        $b->setQttAisance($this->nullInt($p->get('qtt_aisance')));
        $b->setQttJeuSansBallons($this->nullInt($p->get('qtt_jeu_sans_ballons')));
        $b->setQttComprehension($this->nullInt($p->get('qtt_comprehension')));
        $b->setQttDefense($this->nullInt($p->get('qtt_defense')));
        $b->setQttRebondCatcher($this->nullInt($p->get('qtt_rebond_catcher')));
        $b->setQttRebondTransiter($this->nullInt($p->get('qtt_rebond_transiter')));

        // Qualités physiques
        $b->setQpEnchainement($this->nullInt($p->get('qp_enchainement')));
        $b->setQpVitesse($this->nullInt($p->get('qp_vitesse')));
        $b->setQpSoinsDuCorps($this->nullInt($p->get('qp_soins_du_corps')));

        // Texte libre
        $b->setPointsForts($p->get('points_forts') ?: null);
        $b->setAlerteMedicale($p->get('alerte_medicale') ?: null);
        $b->setPointsVigilance($p->get('points_vigilance') ?: null);
        $b->setAxesTravail($p->get('axes_travail') ?: null);
        $b->setBilanRemarques($p->get('bilan_remarques') ?: null);
    }

    /**
     * Convertit une string POST en int ou null.
     * Chaîne vide → null. Nombre invalide → null.
     */
    private function nullInt(?string $v): ?int
    {
        if ($v === null || $v === '') return null;
        $int = filter_var($v, FILTER_VALIDATE_INT);
        return $int === false ? null : (int) $int;
    }

    /**
     * [V2.4 05/07/2026] Délègue à SaisonService : un bilan créé pendant que
     * l'utilisateur regarde la saison X est tagué X (respect du sélecteur),
     * et la bascule vers la saison suivante est automatique (1er juillet).
     */
    private function saisonCourante(): string
    {
        return $this->saisonService->getSaisonActive();
    }
}
