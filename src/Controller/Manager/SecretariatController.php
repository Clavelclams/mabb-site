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
        // [V2.4h] classeur par secteur + pré-inscriptions publiques
        private readonly \App\Repository\Sport\SecteurRepository $secteurRepo,
        private readonly \App\Repository\Sport\PreInscriptionRepository $preInscriptionRepo,
        private readonly \App\Service\Secretariat\PreInscriptionConverter $converter,
        private readonly \App\Repository\Sport\JoueurRepository $joueurRepo,
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
            // [V2.4h] demandes déposées via la vitrine, à traiter
            'nb_pre_inscriptions' => $this->preInscriptionRepo->compterNouvelles($club),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // Tableau des dossiers licences
    // ────────────────────────────────────────────────────────────────────

    /**
     * [V2.4h] LE CLASSEUR — la page licences reproduit l'environnement Excel
     * de la secrétaire : onglets SECTEURS → onglets CATÉGORIES → lignes.
     */
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

        // Onglets secteurs : référentiel Secteur ∪ sites présents dans les
        // dossiers (compat : un site importé sans fiche Secteur reste visible).
        $secteurs = $this->secteurRepo->findByClub($club);
        $nomsSecteurs = array_map(fn($s) => $s->getNom(), $secteurs);
        foreach ($this->dossierRepo->sites($club, $saison) as $s) {
            if (!in_array(mb_strtoupper($s), array_map('mb_strtoupper', $nomsSecteurs), true)) {
                $nomsSecteurs[] = $s;
            }
        }

        // Compteurs par secteur (onglets) — sur la saison complète
        $stats = $this->dossierRepo->statsDashboard($club, $saison);

        // Lignes du secteur affiché ; catégories (sous-onglets) issues du secteur
        $dossiersSecteur = $this->dossierRepo->rechercher($club, $saison, $site ?: null);
        $categoriesSecteur = array_values(array_unique(array_filter(array_map(
            fn(DossierLicence $d) => $d->getCategorie(), $dossiersSecteur
        ))));
        sort($categoriesSecteur);

        $dossiers = array_values(array_filter($dossiersSecteur, function (DossierLicence $d) use ($categorie, $statut) {
            if ($categorie !== '' && $d->getCategorie() !== $categorie) { return false; }
            if ($statut !== '' && $d->getPaiementStatut() !== $statut) { return false; }
            return true;
        }));

        return $this->render('manager/secretariat/licences.html.twig', [
            'club'       => $club,
            'saison'     => $saison,
            'saisons'    => $this->saisonService->getSaisonsDisponibles(),
            'dossiers'   => $dossiers,
            'secteurs'   => $secteurs,
            'noms_secteurs' => $nomsSecteurs,
            'compteurs_site' => $stats['par_site'],
            'categories' => $categoriesSecteur,
            'filtre_site'      => $site,
            'filtre_categorie' => $categorie,
            'filtre_statut'    => $statut,
            'statuts'    => DossierLicence::PAIEMENT_LABELS,
            'nb_pre_inscriptions' => $this->preInscriptionRepo->compterNouvelles($club),
        ]);
    }

    /** [V2.4h] Ajout manuel d'une licenciée depuis le classeur. */
    #[Route('/licences/ajouter', name: 'licence_ajouter', methods: ['POST'])]
    public function ajouterLicence(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);
        if (!$this->isCsrfTokenValid('secretariat_licence_ajouter', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_secretariat_licences');
        }

        $saison = (string) $request->request->get('saison', '');
        if (!$this->saisonService->isValide($saison)) {
            $saison = $this->saisonService->getSaisonActive();
        }
        $nom = trim((string) $request->request->get('nom_complet', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Le nom est obligatoire.');
            return $this->redirectToRoute('manager_secretariat_licences', ['saison' => $saison]);
        }

        // Anti-doublon : si un dossier existe déjà (n° ou nom), on COMPLÈTE
        $numero  = trim((string) $request->request->get('numero_licence', ''));
        $dossier = $this->dossierRepo->trouverPourImport($club, $saison, $numero !== '' ? strtoupper($numero) : null, $nom);
        $nouveau = ($dossier === null);
        if ($nouveau) {
            $dossier = new DossierLicence();
            $dossier->setClub($club)->setSaison($saison)->setNomComplet($nom);
            $this->em->persist($dossier);
        }
        $site = trim((string) $request->request->get('site', ''));
        $dossier->setSite($site ?: ($dossier->getSite() ?? 'À placer'))
            ->setCategorie(trim((string) $request->request->get('categorie', '')) ?: $dossier->getCategorie())
            ->setNumeroLicence($numero ?: $dossier->getNumeroLicence())
            ->setTelephone(trim((string) $request->request->get('telephone', '')) ?: $dossier->getTelephone())
            ->setTarif(trim((string) $request->request->get('tarif', '')) ?: $dossier->getTarif());

        // Lien fiche joueuse si trouvable (anti-doublon nom+prénom normalisés)
        if ($dossier->getJoueur() === null) {
            $cible = \App\Service\Secretariat\NomOutil::normaliser($nom);
            foreach ($this->joueurRepo->findBy(['club' => $club, 'isActive' => true]) as $j) {
                $n1 = \App\Service\Secretariat\NomOutil::normaliser(($j->getNom() ?? '') . ' ' . ($j->getPrenom() ?? ''));
                $n2 = \App\Service\Secretariat\NomOutil::normaliser(($j->getPrenom() ?? '') . ' ' . ($j->getNom() ?? ''));
                if ($cible === $n1 || $cible === $n2) { $dossier->setJoueur($j); break; }
            }
        }

        $this->em->flush();
        $this->addFlash('success', $nouveau
            ? sprintf('%s ajoutée au classeur (%s).', $nom, $dossier->getSite())
            : sprintf('%s existait déjà — dossier complété (pas de doublon).', $nom));
        return $this->redirectToRoute('manager_secretariat_licences', ['saison' => $saison, 'site' => $dossier->getSite()]);
    }

    /** [V2.4h] Déplacer une joueuse vers un autre secteur. */
    #[Route('/licences/{id}/site', name: 'licence_site', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changerSite(Request $request, DossierLicence $dossier): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $dossier);
        if (!$this->isCsrfTokenValid('secretariat_site_' . $dossier->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectDossiers($request, $dossier);
        }
        $site = trim((string) $request->request->get('site', ''));
        if ($site !== '') {
            $dossier->setSite($site);
            $this->em->flush();
            $this->addFlash('success', sprintf('%s placée sur %s.', $dossier->getNomComplet(), $site));
        }
        return $this->redirectDossiers($request, $dossier);
    }

    /** [V2.4h] Édition complète d'un dossier (page dédiée, simple et robuste). */
    #[Route('/licences/{id}/modifier', name: 'licence_modifier', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function modifierLicence(Request $request, DossierLicence $dossier): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $dossier);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('secretariat_modifier_' . $dossier->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_secretariat_licence_modifier', ['id' => $dossier->getId()]);
            }
            $nom = trim((string) $request->request->get('nom_complet', ''));
            if ($nom !== '') { $dossier->setNomComplet($nom); }
            $dossier->setSite(trim((string) $request->request->get('site', '')) ?: null)
                ->setCategorie(trim((string) $request->request->get('categorie', '')) ?: null)
                ->setTypeLicence(trim((string) $request->request->get('type_licence', '')) ?: null)
                ->setNumeroLicence(trim((string) $request->request->get('numero_licence', '')) ?: null)
                ->setTelephone(trim((string) $request->request->get('telephone', '')) ?: null)
                ->setTarif(trim((string) $request->request->get('tarif', '')) ?: null)
                ->setPaiementStatut((string) $request->request->get('paiement_statut', $dossier->getPaiementStatut()))
                ->setNotes(trim((string) $request->request->get('notes', '')) ?: null);
            $ddn = trim((string) $request->request->get('date_naissance', ''));
            if ($ddn !== '') {
                try { $dossier->setDateNaissance(new \DateTimeImmutable($ddn)); } catch (\Exception) {}
            }
            // Aides : champs libres conservés dans le JSON
            $aides = array_filter([
                'aide_mairie'     => trim((string) $request->request->get('aide_mairie', '')),
                'pass'            => trim((string) $request->request->get('pass', '')),
                'cheques_college' => trim((string) $request->request->get('cheques_college', '')),
                'cheques'         => trim((string) $request->request->get('cheques', '')),
                'especes'         => trim((string) $request->request->get('especes', '')),
            ], fn(string $v) => $v !== '');
            $dossier->setAides($aides ?: null);

            $this->em->flush();
            $this->addFlash('success', 'Dossier mis à jour.');
            return $this->redirectToRoute('manager_secretariat_licences', [
                'saison' => $dossier->getSaison(), 'site' => $dossier->getSite(),
            ]);
        }

        $club = $dossier->getClub();
        return $this->render('manager/secretariat/licence_modifier.html.twig', [
            'dossier'  => $dossier,
            'secteurs' => $club ? $this->secteurRepo->findByClub($club) : [],
            'statuts'  => DossierLicence::PAIEMENT_LABELS,
        ]);
    }

    /**
     * [V2.4h] « Préparer la saison » : crée les dossiers manquants depuis les
     * fiches Joueur ACTIVES du club (secteur « À placer », statut À payer).
     * Anti-doublon : une joueuse ayant déjà un dossier cette saison est sautée.
     */
    #[Route('/licences/generer', name: 'licences_generer', methods: ['POST'])]
    public function genererDepuisJoueuses(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);
        if (!$this->isCsrfTokenValid('secretariat_generer', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_secretariat_licences');
        }

        $saison = (string) $request->request->get('saison', '');
        if (!$this->saisonService->isValide($saison)) {
            $saison = $this->saisonService->getSaisonActive();
        }

        // IDs des joueuses ayant DÉJÀ un dossier cette saison
        $dejaDossier = [];
        foreach ($this->dossierRepo->findBy(['club' => $club, 'saison' => $saison]) as $d) {
            if ($d->getJoueur() !== null) { $dejaDossier[(int) $d->getJoueur()->getId()] = true; }
        }

        $crees = 0;
        foreach ($this->joueurRepo->findBy(['club' => $club, 'isActive' => true, 'isTemporaire' => false]) as $j) {
            if (isset($dejaDossier[(int) $j->getId()])) { continue; }
            // Double filet : rapprochement par nom (dossier importé sans lien fiche)
            $nomComplet = mb_strtoupper((string) $j->getNom()) . ' ' . (string) $j->getPrenom();
            $existant = $this->dossierRepo->trouverPourImport($club, $saison, null, $nomComplet);
            if ($existant !== null) {
                if ($existant->getJoueur() === null) { $existant->setJoueur($j); }
                continue;
            }
            $dossier = new DossierLicence();
            $dossier->setClub($club)
                ->setSaison($saison)
                ->setJoueur($j)
                ->setNomComplet($nomComplet)
                ->setDateNaissance($j->getDateNaissance())
                ->setTelephone($j->getTelephone())
                ->setSite('À placer')
                ->setCategorie($j->getEquipe()?->getNom());
            $this->em->persist($dossier);
            $crees++;
        }
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d dossier(s) créé(s) depuis les fiches joueuses (secteur « À placer »). Les joueuses déjà dans le classeur n\'ont PAS été dupliquées.',
            $crees
        ));
        return $this->redirectToRoute('manager_secretariat_licences', ['saison' => $saison, 'site' => 'À placer']);
    }

    // ────────────────────────────────────────────────────────────────────
    // [V2.4h] Secteurs (référentiel : nom + responsable de secteur)
    // ────────────────────────────────────────────────────────────────────

    #[Route('/secteurs', name: 'secteur_enregistrer', methods: ['POST'])]
    public function enregistrerSecteur(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);
        if (!$this->isCsrfTokenValid('secretariat_secteur', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_secretariat_licences');
        }

        $nom = trim((string) $request->request->get('nom', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Le nom du secteur est obligatoire.');
            return $this->redirectToRoute('manager_secretariat_licences');
        }

        $secteur = $this->secteurRepo->findOneByClubEtNom($club, $nom) ?? new \App\Entity\Sport\Secteur();
        if ($secteur->getId() === null) {
            $secteur->setClub($club)->setNom($nom);
            $this->em->persist($secteur);
        }
        $secteur->setResponsableNom(trim((string) $request->request->get('responsable_nom', '')) ?: null);
        $secteur->setResponsableTelephone(trim((string) $request->request->get('responsable_telephone', '')) ?: null);
        $this->em->flush();

        $this->addFlash('success', sprintf('Secteur %s enregistré%s.', $secteur->getNom(),
            $secteur->getResponsableNom() ? ' (resp. ' . $secteur->getResponsableNom() . ')' : ''));
        return $this->redirectToRoute('manager_secretariat_licences', ['site' => $secteur->getNom()]);
    }

    // ────────────────────────────────────────────────────────────────────
    // [V2.4h] Pré-inscriptions (déposées via la vitrine publique)
    // ────────────────────────────────────────────────────────────────────

    #[Route('/pre-inscriptions', name: 'pre_inscriptions', methods: ['GET'])]
    public function preInscriptions(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);

        $statut = (string) $request->query->get('statut', \App\Entity\Sport\PreInscription::STATUT_NOUVELLE);
        $liste  = $this->preInscriptionRepo->findByClubEtStatut($club, $statut ?: null);

        // Détection de fiche existante pour chaque demande (affiche « fiche
        // trouvée : lier » AVANT conversion — anti-doublon visible)
        $fichesDetectees = [];
        foreach ($liste as $pre) {
            if ($pre->isNouvelle()) {
                $fichesDetectees[$pre->getId()] = $this->converter->detecterJoueuse($pre);
            }
        }

        return $this->render('manager/secretariat/pre_inscriptions.html.twig', [
            'club'     => $club,
            'liste'    => $liste,
            'statut'   => $statut,
            'fiches_detectees' => $fichesDetectees,
            'secteurs' => $this->secteurRepo->findByClub($club),
            'nb_nouvelles' => $this->preInscriptionRepo->compterNouvelles($club),
        ]);
    }

    #[Route('/pre-inscriptions/{id}/convertir', name: 'pre_inscription_convertir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function convertirPreInscription(Request $request, \App\Entity\Sport\PreInscription $pre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $pre);
        if (!$this->isCsrfTokenValid('pre_convertir_' . $pre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_secretariat_pre_inscriptions');
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\Core\User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $dossier = $this->converter->convertir(
                $pre,
                $user,
                $request->request->getBoolean('creer_fiche'),
                trim((string) $request->request->get('secteur', '')) ?: null,
                trim((string) $request->request->get('categorie', '')) ?: null,
                trim((string) $request->request->get('tarif', '')) ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('manager_secretariat_pre_inscriptions');
        }

        $this->addFlash('success', sprintf(
            'Pré-inscription convertie : %s → classeur %s.',
            $pre->getNomComplet(),
            $dossier->getSite() ?? '?'
        ));
        return $this->redirectToRoute('manager_secretariat_pre_inscriptions');
    }

    #[Route('/pre-inscriptions/{id}/refuser', name: 'pre_inscription_refuser', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function refuserPreInscription(Request $request, \App\Entity\Sport\PreInscription $pre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $pre);
        if (!$this->isCsrfTokenValid('pre_refuser_' . $pre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_secretariat_pre_inscriptions');
        }

        $user = $this->getUser();
        $pre->setStatut(\App\Entity\Sport\PreInscription::STATUT_REFUSEE);
        $pre->setTraiteLe(new \DateTimeImmutable());
        $pre->setTraitePar($user instanceof \App\Entity\Core\User ? $user : null);
        $pre->setNoteTraitement(trim((string) $request->request->get('note', '')) ?: null);
        $this->em->flush();

        $this->addFlash('warning', sprintf('Pré-inscription de %s refusée.', $pre->getNomComplet()));
        return $this->redirectToRoute('manager_secretariat_pre_inscriptions');
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
    // [V2.4l] L'ANNUAIRE — « la fiche de chacun », tout lisible d'un coup
    // ────────────────────────────────────────────────────────────────────

    /**
     * L'annuaire du club : UNE recherche, et pour chaque personne TOUT est
     * visible sans cliquer (naissance, catégorie, téléphones, parents et
     * leurs coordonnées, adresse, responsable de secteur, paiement).
     *
     * Pensé pour une secrétaire qui vient du PAPIER : gros caractères,
     * zéro manipulation, bouton Imprimer. Fusionne les fiches Joueur, les
     * dossiers licences de la saison, les contacts parents et signale les
     * pré-inscriptions à traiter. Recherche insensible aux accents
     * (NomOutil), sur les noms ET les noms de parents.
     */
    #[Route('/annuaire', name: 'annuaire', methods: ['GET'])]
    public function annuaire(Request $request): Response
    {
        $club = $this->clubOuRedirect();
        if ($club instanceof Response) { return $club; }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_SECRETARIAT, $club);

        $saison = $this->saisonDemandee($request);
        $q      = trim((string) $request->query->get('q', ''));
        $qNorm  = $q !== '' ? \App\Service\Secretariat\NomOutil::normaliser($q) : '';
        // [V2.4l] Filtre par SECTEUR (demande secrétaire : « tel joueur = tel
        // secteur » pour gérer ses classeurs) — s'applique sur le site du dossier.
        $secteurFiltre = trim((string) $request->query->get('secteur', ''));

        // --- Sources ---
        $joueuses = $this->joueurRepo->findBy(['club' => $club, 'isActive' => true, 'isTemporaire' => false], ['nom' => 'ASC', 'prenom' => 'ASC']);
        $dossiers = $this->dossierRepo->findBy(['club' => $club, 'saison' => $saison]);
        $dossierParJoueur = [];
        $dossiersSansFiche = [];
        foreach ($dossiers as $d) {
            if ($d->getJoueur() !== null) {
                $dossierParJoueur[(int) $d->getJoueur()->getId()] = $d;
            } else {
                $dossiersSansFiche[] = $d;
            }
        }
        // Contacts parents en une requête (indexés par joueuse)
        $responsablesParJoueur = [];
        if ($joueuses !== []) {
            foreach ($this->em->getRepository(\App\Entity\Sport\ResponsableLegal::class)
                ->findBy(['joueur' => $joueuses]) as $r) {
                $responsablesParJoueur[(int) $r->getJoueur()->getId()][] = $r;
            }
        }

        // --- Fiches unifiées ---
        $fiches = [];
        foreach ($joueuses as $j) {
            $dossier = $dossierParJoueur[(int) $j->getId()] ?? null;
            $fiches[] = [
                'type'         => 'joueuse',
                'joueur'       => $j,
                'dossier'      => $dossier,
                'nom'          => (string) $j->getNom(),
                'prenom'       => (string) $j->getPrenom(),
                'naissance'    => $j->getDateNaissance(),
                'categorie'    => $dossier?->getCategorie() ?? $j->getEquipe()?->getNom(),
                'telephone'    => $j->getTelephone() ?? $dossier?->getTelephone(),
                'site'         => $dossier?->getSite(),
                'parents'      => $responsablesParJoueur[(int) $j->getId()] ?? [],
            ];
        }
        foreach ($dossiersSansFiche as $d) {
            $fiches[] = [
                'type'      => 'dossier',
                'joueur'    => null,
                'dossier'   => $d,
                'nom'       => (string) $d->getNomComplet(),
                'prenom'    => '',
                'naissance' => $d->getDateNaissance(),
                'categorie' => $d->getCategorie(),
                'telephone' => $d->getTelephone(),
                'site'      => $d->getSite(),
                'parents'   => [],
            ];
        }

        // --- Filtre recherche (nom, prénom, parents, téléphone) ---
        if ($qNorm !== '') {
            $fiches = array_values(array_filter($fiches, function (array $f) use ($qNorm) {
                $meule = $f['nom'] . ' ' . $f['prenom'] . ' ' . ($f['telephone'] ?? '');
                foreach ($f['parents'] as $p) {
                    $meule .= ' ' . $p->getNomComplet() . ' ' . ($p->getTelephone() ?? '') . ' ' . ($p->getEmail() ?? '');
                }
                return str_contains(\App\Service\Secretariat\NomOutil::normaliser($meule), $qNorm);
            }));
        }
        // Filtre secteur ('À placer' inclut les fiches SANS dossier : à traiter)
        if ($secteurFiltre !== '') {
            $fiches = array_values(array_filter($fiches, function (array $f) use ($secteurFiltre) {
                $site = $f['site'] ?? ($f['dossier'] === null ? 'À placer' : null);
                return $site !== null && mb_strtoupper($site) === mb_strtoupper($secteurFiltre);
            }));
        }
        usort($fiches, fn(array $a, array $b) => [mb_strtoupper($a['nom']), $a['prenom']] <=> [mb_strtoupper($b['nom']), $b['prenom']]);

        // Pré-inscriptions à traiter qui matchent la recherche (rappel doux)
        $preInscriptionsMatch = [];
        foreach ($this->preInscriptionRepo->findByClubEtStatut($club, \App\Entity\Sport\PreInscription::STATUT_NOUVELLE) as $pre) {
            if ($qNorm === '' || str_contains(\App\Service\Secretariat\NomOutil::normaliser($pre->getNomComplet() . ' ' . ($pre->getParentNom() ?? '')), $qNorm)) {
                $preInscriptionsMatch[] = $pre;
            }
        }

        return $this->render('manager/secretariat/annuaire.html.twig', [
            'club'    => $club,
            'saison'  => $saison,
            'saisons' => $this->saisonService->getSaisonsDisponibles(),
            'q'       => $q,
            'fiches'  => $fiches,
            'pre_inscriptions' => $preInscriptionsMatch,
            'secteurs'       => $this->secteurRepo->findByClub($club),
            'filtre_secteur' => $secteurFiltre,
        ]);
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
        // [V2.4l] Les actions lancées depuis l'ANNUAIRE y retournent (avec
        // la recherche et le filtre secteur intacts) — pas vers le classeur.
        if ((string) $request->request->get('retour', '') === 'annuaire') {
            return $this->redirectToRoute('manager_secretariat_annuaire', array_filter([
                'saison'  => $dossier->getSaison(),
                'q'       => (string) $request->request->get('retour_q', ''),
                'secteur' => (string) $request->request->get('retour_secteur', ''),
            ]));
        }
        return $this->redirectToRoute('manager_secretariat_licences', array_filter([
            'saison'    => $dossier->getSaison(),
            'site'      => (string) $request->request->get('retour_site', ''),
            'categorie' => (string) $request->request->get('retour_categorie', ''),
            'statut'    => (string) $request->request->get('retour_statut', ''),
        ]));
    }
}
