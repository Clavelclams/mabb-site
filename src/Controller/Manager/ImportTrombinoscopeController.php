<?php

namespace App\Controller\Manager;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\TrombinoscopeParserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ImportTrombinoscopeController — upload + preview + création en masse.
 *
 * Workflow utilisateur :
 *   1. /import/trombinoscope                → page upload PDF
 *   2. POST upload                          → parsing + stockage en session
 *   3. /import/trombinoscope/preview        → liste détectée avec checkboxes
 *   4. POST confirm                         → création/MAJ en BDD
 *
 * Sécurité : réservé aux CLUB_STAFF (création de joueuses = action sensible).
 *
 * Détection des doublons :
 *   - Match par licence FFBB en priorité (clé unique de l'écosystème basket)
 *   - À défaut, match par nom + prenom + date_naissance
 *   - Si match → proposer une mise à jour (avec ancien/nouveau visible)
 */
class ImportTrombinoscopeController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly TrombinoscopeParserService $parser,
        private readonly EquipeRepository $equipeRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Page upload du PDF trombinoscope.
     */
    #[Route('/import/trombinoscope', name: 'manager_import_trombi_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('upload_trombi', $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            /** @var UploadedFile|null $pdfFile */
            $pdfFile = $request->files->get('pdf');
            if (!$pdfFile) {
                $this->addFlash('error', 'Aucun fichier sélectionné.');
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            // VÉRIFICATION CRITIQUE : l'upload a-t-il abouti côté serveur ?
            // Si limite PHP dépassée (upload_max_filesize, post_max_size), Symfony
            // bind quand même un UploadedFile MAIS avec un chemin vide, ce qui
            // fait planter getMimeType(). On intercepte avec un message clair.
            if (!$pdfFile->isValid()) {
                $this->addFlash('error', sprintf(
                    'Échec de l\'upload : %s. Vérifie la taille du fichier (max 20 Mo) et la config PHP.',
                    $pdfFile->getErrorMessage()
                ));
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            // Validation basique : doit être un PDF
            $extension = strtolower($pdfFile->getClientOriginalExtension());
            if ($extension !== 'pdf') {
                $this->addFlash('error', 'Le fichier doit être un PDF.');
                return $this->redirectToRoute('manager_import_trombi_upload');
            }
            // getMimeType() ne plante plus maintenant que isValid() a passé
            if ($pdfFile->getMimeType() !== 'application/pdf') {
                $this->addFlash('error', 'Le fichier n\'est pas un PDF valide.');
                return $this->redirectToRoute('manager_import_trombi_upload');
            }
            if ($pdfFile->getSize() > 20 * 1024 * 1024) {
                $this->addFlash('error', 'Fichier trop volumineux (max 20 Mo).');
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            // Stockage temporaire (var/temp_import/)
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp_import';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }
            $tempName = uniqid('trombi_') . '.pdf';
            try {
                $pdfFile->move($tempDir, $tempName);
            } catch (FileException $e) {
                $this->addFlash('error', 'Erreur lors du téléversement : ' . $e->getMessage());
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            // Parse du PDF
            try {
                $resultat = $this->parser->parse($tempDir . '/' . $tempName);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Impossible de lire le PDF : ' . $e->getMessage());
                @unlink($tempDir . '/' . $tempName);
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            if (empty($resultat['joueuses'])) {
                $this->addFlash('warning', 'Aucune joueuse détectée dans ce PDF. Vérifie le format.');
                @unlink($tempDir . '/' . $tempName);
                return $this->redirectToRoute('manager_import_trombi_upload');
            }

            // Stocke en session pour l'étape preview
            $session = $request->getSession();
            $session->set('trombi_import', [
                'fichier_temp' => $tempName,
                'resultat'     => $resultat,
            ]);

            return $this->redirectToRoute('manager_import_trombi_preview');
        }

        return $this->render('manager/import/trombi_upload.html.twig', [
            'club' => $club,
        ]);
    }

    /**
     * Page de prévisualisation : montre les joueuses détectées + statut (nouvelle/MAJ).
     */
    #[Route('/import/trombinoscope/preview', name: 'manager_import_trombi_preview', methods: ['GET', 'POST'])]
    public function preview(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $session = $request->getSession();
        $data = $session->get('trombi_import');
        if (!$data) {
            $this->addFlash('warning', 'Aucun import en cours.');
            return $this->redirectToRoute('manager_import_trombi_upload');
        }

        $joueusesDetectees = $data['resultat']['joueuses'];
        $equipeInfo        = $data['resultat']['equipe'];

        // Code club FFBB de l'équipe (ex: HDF0080036 pour MABB). Sert à
        // détecter les joueuses dont la licence FFBB est rattachée à un
        // autre club (mutation en cours, double licence, etc.).
        $clubCodeEquipe = $equipeInfo['club_code'] ?? null;

        // ====================================================================
        // Heuristique de détection des "staff probables" (coach, dirigeant,
        // entraîneur, etc.). Le trombinoscope FFBB d'une équipe liste
        // joueurs ET staff licencié, sans distinction explicite. On déduit :
        //   1. Sexe majoritaire = sexe de l'équipe (ex: RF3 = féminine)
        //   2. Toute personne du sexe minoritaire = staff probable
        //      (Willy président + coach interne MABB dans un trombi féminin)
        //   3. Toute personne licenciée dans un autre club = staff probable
        //      (Clavel coach MABB licencié ASCBB)
        // Limite connue : un coach du sexe majoritaire passera comme joueur.
        // L'admin peut décocher manuellement dans ce cas.
        // ====================================================================
        $compteSexes = ['Masculin' => 0, 'Féminin' => 0];
        foreach ($joueusesDetectees as $j) {
            $sexe = $j['sexe'] ?? null;
            if (isset($compteSexes[$sexe])) {
                $compteSexes[$sexe]++;
            }
        }
        $sexeMajoritaire = $compteSexes['Féminin'] >= $compteSexes['Masculin']
            ? 'Féminin'
            : 'Masculin';

        // Enrichit chaque joueuse détectée avec son statut (nouvelle/existante)
        // et propose une catégorie d'équipe valide.
        $categoriesValides = Equipe::CATEGORIES;
        $apercu = [];
        foreach ($joueusesDetectees as $i => $j) {
            // Match par licence en priorité
            $existante = null;
            if ($j['licence']) {
                $existante = $this->joueurRepository->findOneBy([
                    'club'    => $club,
                    'licence' => $j['licence'],
                ]);
            }
            // Si pas trouvée par licence, match par nom+prénom+DDN
            if (!$existante && $j['ddn']) {
                $existante = $this->joueurRepository->findOneBy([
                    'club'          => $club,
                    'prenom'        => $j['prenom'],
                    'nom'           => $j['nom'],
                    'dateNaissance' => new \DateTimeImmutable($j['ddn']),
                ]);
            }

            // ============================================================
            // Catégorie : priorité au PDF, fallback sur le calcul
            // depuis l'année de naissance (déduite de la licence FFBB).
            // ============================================================
            // Cas particulier : "Seniors" générique du PDF → on mappe sur
            // "Senior F" ou "Senior H" selon le sexe (les CATEGORIES MABB
            // distinguent les deux, le PDF non).
            $categoriePDF = $j['categorie'];
            if ($categoriePDF === 'Seniors') {
                $categoriePDF = $j['sexe'] === 'Masculin' ? 'Senior H' : 'Senior F';
            }

            // Si la cat du PDF n'est pas dans nos catégories valides
            // (ex: U19 du PDF qui n'existe pas chez MABB), on bascule sur
            // la catégorie calculée pour la saison sportive courante.
            $categorieMappee = in_array($categoriePDF, $categoriesValides, true)
                ? $categoriePDF
                : $j['categorie_calculee'];

            // Et si même la calculée n'est pas valide, on laisse null
            // (à remplir manuellement par l'admin).
            if (!in_array($categorieMappee, $categoriesValides, true)) {
                $categorieMappee = null;
            }

            // Détection licence externe : si le code club FFBB de la joueuse
            // diffère de celui de l'équipe, c'est une mutation/double licence.
            // Le coach garde la décision finale via la coche d'import.
            $clubCodeJoueuse = $j['club_code'] ?? null;
            $clubNomJoueuse  = $j['club_nom'] ?? null;
            $estExterne = $clubCodeEquipe !== null
                && $clubCodeJoueuse !== null
                && $clubCodeJoueuse !== $clubCodeEquipe;

            // Détection du rôle probable :
            //   - sexe minoritaire dans le trombi → staff probable (Willy)
            //   - club externe → staff probable (Clavel)
            //   - sinon → joueur
            $sexeMinoritaire = ($j['sexe'] !== null && $j['sexe'] !== $sexeMajoritaire);
            $roleProbable = ($sexeMinoritaire || $estExterne) ? 'Staff' : 'Joueur';

            $apercu[] = [
                'index'              => $i,
                'prenom'             => $j['prenom'],
                'nom'                => $j['nom'],
                'licence'            => $j['licence'],
                'ddn'                => $j['ddn'],
                'sexe'               => $j['sexe'],
                'categorie'          => $categorieMappee,
                'categorie_pdf'      => $j['categorie'],       // catégorie brute du PDF (avant mapping)
                'categorie_calculee' => $j['categorie_calculee'],
                'annee_naissance'    => $j['annee_naissance'],
                'club_code'          => $clubCodeJoueuse,
                'club_nom'           => $clubNomJoueuse,
                'est_externe'        => $estExterne,
                'role_probable'      => $roleProbable,
                'existante'          => $existante,
                'statut'             => $existante ? 'maj' : 'nouvelle',
            ];
        }

        // ====================================================================
        // POST : confirmation de l'import
        // ====================================================================
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('confirm_trombi', $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_import_trombi_preview');
            }

            // Indices à importer (cochés par l'admin)
            $indices = $request->request->all('importer');

            // Affectation équipe (optionnel — proposée par l'admin)
            $equipeIdAffectation = (int) ($request->request->get('equipe_affectation') ?? 0);
            $equipeAffectation = null;
            if ($equipeIdAffectation > 0) {
                $equipeAffectation = $this->equipeRepository->find($equipeIdAffectation);
                if (!$equipeAffectation || $equipeAffectation->getClub()->getId() !== $club->getId()) {
                    $this->addFlash('error', 'Équipe invalide.');
                    return $this->redirectToRoute('manager_import_trombi_preview');
                }
            }

            $countCree = 0;
            $countMaj = 0;
            foreach ($apercu as $a) {
                if (!isset($indices[$a['index']])) {
                    continue;  // Pas cochée, on saute
                }

                if ($a['existante']) {
                    // Mise à jour ciblée (on ne touche pas aux champs métier sensibles)
                    $j = $a['existante'];
                    if (!$j->getLicence() && $a['licence']) {
                        $j->setLicence($a['licence']);
                    }
                    if (!$j->getDateNaissance() && $a['ddn']) {
                        $j->setDateNaissance(new \DateTimeImmutable($a['ddn']));
                    }
                    if ($equipeAffectation && !$j->getEquipe()) {
                        $j->setEquipe($equipeAffectation);
                    }
                    $countMaj++;
                } else {
                    // Création
                    $j = new Joueur();
                    $j->setClub($club);
                    $j->setPrenom($a['prenom']);
                    $j->setNom($a['nom']);
                    if ($a['licence']) $j->setLicence($a['licence']);
                    if ($a['ddn']) $j->setDateNaissance(new \DateTimeImmutable($a['ddn']));
                    if ($equipeAffectation) $j->setEquipe($equipeAffectation);
                    $j->setIsActive(true);
                    $this->em->persist($j);
                    $countCree++;
                }
            }
            $this->em->flush();

            // Nettoyage : suppression du PDF temp + session
            $tempPath = $this->getParameter('kernel.project_dir') . '/var/temp_import/' . $data['fichier_temp'];
            @unlink($tempPath);
            $session->remove('trombi_import');

            $this->addFlash('success', sprintf(
                'Import terminé : %d nouvelle(s) joueuse(s) créée(s), %d mise(s) à jour.',
                $countCree,
                $countMaj
            ));
            return $this->redirectToRoute('manager_joueur_index');
        }

        // Liste des équipes pour le selecteur d'affectation
        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        return $this->render('manager/import/trombi_preview.html.twig', [
            'club'        => $club,
            'equipe_info' => $equipeInfo,
            'apercu'      => $apercu,
            'equipes'     => $equipes,
            'nb_total'    => count($apercu),
            'nb_nouvelle' => count(array_filter($apercu, fn($a) => $a['statut'] === 'nouvelle')),
            'nb_maj'      => count(array_filter($apercu, fn($a) => $a['statut'] === 'maj')),
            'nb_externe'  => count(array_filter($apercu, fn($a) => $a['est_externe'])),
            'nb_staff'    => count(array_filter($apercu, fn($a) => $a['role_probable'] === 'Staff')),
        ]);
    }
}
