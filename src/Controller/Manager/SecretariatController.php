<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\DossierLicence;
use App\Repository\Sport\DossierLicenceRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\SaisonService;
use App\Service\Secretariat\SecretariatImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SecretariatController — l'espace de travail de la secrétaire [V2.4g].
 *
 * Remplace les fichiers Excel « LICENCIÉS <SITE> <SAISON> » :
 *   GET  /secretariat                          → dashboard (compteurs, relances)
 *   GET  /secretariat/licences                 → tableau filtrable des dossiers
 *   POST /secretariat/licences/{id}/statut     → changer le statut de paiement
 *   POST /secretariat/licences/{id}/relance    → marquer « relancé aujourd'hui »
 *   GET|POST /secretariat/import               → import des Excel (licenciés + parents)
 *
 * Accès : ClubVoter::CLUB_SECRETARIAT (DIRIGEANT + SECRETAIRE). Les dossiers
 * contiennent des données de mineures → pas ouvert à COACH/STAFF.
 */
#[Route('/secretariat', name: 'manager_secretariat_')]
class SecretariatController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly DossierLicenceRepository $dossierRepo,
        private readonly SaisonService $saisonService,
        private readonly SecretariatImportService $importService,
        private readonly EntityManagerInterface $em,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // Dashboard
    // ────────────────────────────────────────────────────────────────────

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);

        $saison = $this->saisonDemandee($request);
        $stats  = $this->dossierRepo->statsDashboard($club, $saison);

        // Les 15 dossiers les plus urgents à relancer : jamais relancés d'abord,
        // puis relance la plus ancienne.
        $aRelancer = array_filter(
            $this->dossierRepo->rechercher($club, $saison),
            fn(DossierLicence $d) => $d->estArelancer()
        );
        usort($aRelancer, function (DossierLicence $a, DossierLicence $b) {
            return ($a->getRelanceLe()?->getTimestamp() ?? 0) <=> ($b->getRelanceLe()?->getTimestamp() ?? 0);
        });

        return $this->render('manager/secretariat/dashboard.html.twig', [
            'club'      => $club,
            'saison'    => $saison,
            'saisons'   => $this->saisonService->getSaisonsDisponibles(),
            'stats'     => $stats,
            'a_relancer' => array_slice($aRelancer, 0, 15),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Tableau des dossiers licences
    // ────────────────────────────────────────────────────────────────────

    #[Route('/licences', name: 'licences', methods: ['GET'])]
    public function licences(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);

        $saison    = $this->saisonDemandee($request);
        $site      = (string) $request->query->get('site', '');
        $categorie = (string) $request->query->get('categorie', '');
        $statut    = (string) $request->query->get('statut', '');

        return $this->render('manager/secretariat/licences.html.twig', [
            'club'       => $club,
            'saison'     => $saison,
            'saisons'    => $this->saisonService->getSaisonsDisponibles(),
            'dossiers'   => $this->dossierRepo->rechercher($club, $saison, $site ?: null, $categorie ?: null, $statut ?: null),
            'sites'      => $this->dossierRepo->sites($club, $saison),
            'categories' => $this->dossierRepo->categories($club, $saison),
            'filtre_site'      => $site,
            'filtre_categorie' => $categorie,
            'filtre_statut'    => $statut,
            'statuts'    => DossierLicence::PAIEMENT_LABELS,
        ]);
    }

    #[Route('/licences/{id}/statut', name: 'licence_statut', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changerStatut(Request $request, DossierLicence $dossier): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $dossier);
        if (!$this->isCsrfTokenValid('secretariat_statut_' . $dossier->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectDossiers($request, $dossier);
        }

        $statut = (string) $request->request->get('statut', '');
        if (in_array($statut, DossierLicence::PAIEMENT_STATUTS, true)) {
            $dossier->setPaiementStatut($statut);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s → %s.', $dossier->getNomComplet(), $dossier->getPaiementLabel()));
        }
        return $this->redirectDossiers($request, $dossier);
    }

    #[Route('/licences/{id}/relance', name: 'licence_relance', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function marquerRelance(Request $request, DossierLicence $dossier): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $dossier);
        if (!$this->isCsrfTokenValid('secretariat_relance_' . $dossier->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectDossiers($request, $dossier);
        }

        $dossier->setRelanceLe(new \DateTimeImmutable('today'));
        $dossier->setRelanceNote(trim((string) $request->request->get('note', '')) ?: null);
        $this->em->flush();

        $this->addFlash('success', sprintf('Relance notée pour %s (%s).', $dossier->getNomComplet(), $dossier->getTelephone() ?? 'pas de n°'));
        return $this->redirectDossiers($request, $dossier);
    }

    // ────────────────────────────────────────────────────────────────────
    // Responsables légaux (depuis la fiche joueuse)
    // ────────────────────────────────────────────────────────────────────

    #[Route('/joueuses/{id}/responsables', name: 'responsable_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouterResponsable(Request $request, \App\Entity\Sport\Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $joueur);
        if (!$this->isCsrfTokenValid('responsable_add_' . $joueur->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $nom = trim((string) $request->request->get('nom_complet', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Le nom du responsable est obligatoire.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $r = new \App\Entity\Sport\ResponsableLegal();
        $r->setJoueur($joueur)
          ->setNomComplet($nom)
          ->setTelephone(trim((string) $request->request->get('telephone', '')) ?: null)
          ->setTelephone2(trim((string) $request->request->get('telephone2', '')) ?: null)
          ->setEmail(trim((string) $request->request->get('email', '')) ?: null)
          ->setAdresse(trim((string) $request->request->get('adresse', '')) ?: null)
          ->setCodePostal(trim((string) $request->request->get('code_postal', '')) ?: null);
        $this->em->persist($r);
        $this->em->flush();

        $this->addFlash('success', 'Responsable légal ajouté.');
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }

    #[Route('/responsables/{id}/supprimer', name: 'responsable_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function supprimerResponsable(Request $request, \App\Entity\Sport\ResponsableLegal $responsable): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $responsable);
        $joueurId = (int) $responsable->getJoueur()?->getId();
        if (!$this->isCsrfTokenValid('responsable_delete_' . $responsable->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueurId]);
        }

        $this->em->remove($responsable);
        $this->em->flush();
        $this->addFlash('success', 'Contact supprimé.');
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueurId]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Import des Excel
    // ────────────────────────────────────────────────────────────────────

    #[Route('/import', name: 'import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);

        $rapport = null;
        $type    = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('secretariat_import', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_secretariat_import');
            }

            $fichier = $request->files->get('fichier');
            $type    = (string) $request->request->get('type', 'licencies');
            $dryRun  = $request->request->getBoolean('dry_run');

            if (!$fichier instanceof UploadedFile) {
                $this->addFlash('error', 'Aucun fichier reçu.');
            } elseif (!in_array(strtolower((string) $fichier->getClientOriginalExtension()), ['xlsx', 'xls'], true)) {
                $this->addFlash('error', 'Format non reconnu : envoie un fichier Excel (.xlsx).');
            } else {
                try {
                    if ($type === 'parents') {
                        $rapport = $this->importService->importParents($fichier->getPathname(), $club, $dryRun);
                    } else {
                        $saison = (string) $request->request->get('saison', '');
                        if (!$this->saisonService->isValide($saison)) {
                            $saison = $this->saisonService->getSaisonActive();
                        }
                        $site = trim((string) $request->request->get('site', '')) ?: 'Non précisé';
                        $rapport = $this->importService->importLicencies($fichier->getPathname(), $club, $saison, $site, $dryRun);
                    }
                    $this->addFlash($dryRun ? 'info' : 'success', $dryRun
                        ? 'Simulation terminée — RIEN n\'a été enregistré. Vérifie le rapport puis relance sans « simulation ».'
                        : 'Import terminé.');
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Import impossible : ' . $e->getMessage());
                }
            }
        }

        return $this->render('manager/secretariat/import.html.twig', [
            'club'    => $club,
            'saisons' => $this->saisonService->getSaisonsDisponibles(),
            'saison_active' => $this->saisonService->getSaisonActive(),
            'rapport' => $rapport,
            'type'    => $type,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ────────────────────────────────────────────────────────────────────

    private function clubOuRedirect(): \App\Entity\Core\Club|Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if ($club === null) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        return $club;
    }

    private function saisonDemandee(Request $request): string
    {
        $saison = (string) $request->query->get('saison', '');
        return $this->saisonService->isValide($saison) ? $saison : $this->saisonService->getSaisonActive();
    }

    /** Retour à la liste en conservant les filtres (transmis en champs cachés). */
    private function redirectDossiers(Request $request, DossierLicence $dossier): Response
    {
        return $this->redirectToRoute('manager_secretariat_licences', array_filter([
            'saison'    => $dossier->getSaison(),
            'site'      => (string) $request->request->get('retour_site', ''),
            'categorie' => (string) $request->request->get('retour_categorie', ''),
            'statut'    => (string) $request->request->get('retour_statut', ''),
        ]));
    }
}
