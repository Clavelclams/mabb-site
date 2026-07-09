<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Repository\Sport\EquipeRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\Import\ImportRencontresService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ImportRencontresController — import web des rencontres FFBB (xlsx).
 *
 * Workflow (calqué sur ImportTrombinoscopeController) :
 *   1. GET  /rencontres/import-ffbb          → formulaire (fichier + équipe)
 *   2. POST /rencontres/import-ffbb          → validation + stockage temp +
 *                                              parsing DRY-RUN → session
 *   3. GET  /rencontres/import-ffbb/apercu   → aperçu des matchs détectés
 *   4. POST /rencontres/import-ffbb/apercu   → écriture réelle en base
 *
 * Sécurité :
 *   - CLUB_STAFF requis (création de rencontres = action sensible) ;
 *   - le club est TOUJOURS le club courant (TenantResolver), jamais un id posté ;
 *   - l'équipe choisie est re-validée comme appartenant au club (anti-IDOR) ;
 *   - la saison est déduite de l'équipe (pas de saisie → pas d'incohérence).
 */
class ImportRencontresController extends AbstractController
{
    private const SESSION_KEY = 'import_rencontres';

    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EquipeRepository $equipeRepository,
        private readonly ImportRencontresService $importer,
    ) {
    }

    #[Route('/rencontres/import-ffbb', name: 'manager_rencontre_import_ffbb', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');

            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $equipes = $this->equipeRepository->findBy(
            ['club' => $club, 'isActive' => true],
            ['categorie' => 'ASC']
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import_rencontres', (string) $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            // Équipe cible — re-validée comme appartenant au club courant.
            $equipeId = (int) $request->request->get('equipe_id', 0);
            $equipe   = $equipeId > 0 ? $this->equipeRepository->find($equipeId) : null;
            if (!$equipe || $equipe->getClub()->getId() !== $club->getId()) {
                $this->addFlash('error', 'Choisis une équipe valide de ton club.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            /** @var UploadedFile|null $file */
            $file = $request->files->get('xlsx');
            if (!$file) {
                $this->addFlash('error', 'Aucun fichier sélectionné.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }
            if (!$file->isValid()) {
                $this->addFlash('error', sprintf(
                    'Échec de l\'upload : %s. Vérifie la taille (max 5 Mo) et la config PHP.',
                    $file->getErrorMessage()
                ));

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }
            if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
                $this->addFlash('error', 'Le fichier doit être un .xlsx exporté depuis FFBB.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }
            if ($file->getSize() > 5 * 1024 * 1024) {
                $this->addFlash('error', 'Fichier trop volumineux (max 5 Mo).');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            // Stockage temporaire.
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/temp_import';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0775, true);
            }
            $tempName = uniqid('renc_') . '.xlsx';
            try {
                $file->move($tempDir, $tempName);
            } catch (FileException $e) {
                $this->addFlash('error', 'Erreur lors du téléversement : ' . $e->getMessage());

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            // Parsing DRY-RUN (aucune écriture).
            $saison = $equipe->getSaison();
            try {
                $resultat = $this->importer->importFromFile($tempDir . '/' . $tempName, $club, $equipe, $saison, true);
            } catch (\Throwable $e) {
                @unlink($tempDir . '/' . $tempName);
                $this->addFlash('error', 'Impossible de lire le fichier : ' . $e->getMessage());

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            if ($resultat['equipe_detectee'] === null || $resultat['apercu'] === []) {
                @unlink($tempDir . '/' . $tempName);
                $this->addFlash('warning',
                    'Aucun match exploitable détecté. Vérifie que le fichier est bien l\'export « Rechercher une rencontre » de cette équipe '
                    . '(ou que ces matchs ne sont pas déjà tous importés).'
                );

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            $request->getSession()->set(self::SESSION_KEY, [
                'fichier_temp' => $tempName,
                'equipe_id'    => $equipe->getId(),
                'equipe_nom'   => $equipe->getNom(),
                'saison'       => $saison,
                'resultat'     => $resultat,
            ]);

            return $this->redirectToRoute('manager_rencontre_import_ffbb_apercu');
        }

        return $this->render('manager/import/rencontres_upload.html.twig', [
            'club'    => $club,
            'equipes' => $equipes,
        ]);
    }

    #[Route('/rencontres/import-ffbb/apercu', name: 'manager_rencontre_import_ffbb_apercu', methods: ['GET', 'POST'])]
    public function apercu(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        $session = $request->getSession();
        $data    = $session->get(self::SESSION_KEY);
        if (!$data) {
            $this->addFlash('warning', 'Aucun import en cours.');

            return $this->redirectToRoute('manager_rencontre_import_ffbb');
        }

        $tempPath = $this->getParameter('kernel.project_dir') . '/var/temp_import/' . $data['fichier_temp'];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('confirm_rencontres', (string) $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb_apercu');
            }

            // Recharge l'équipe + re-valide l'appartenance au club (anti-IDOR).
            $equipe = $this->equipeRepository->find((int) $data['equipe_id']);
            if (!$equipe || $equipe->getClub()->getId() !== $club->getId()) {
                @unlink($tempPath);
                $session->remove(self::SESSION_KEY);
                $this->addFlash('error', 'Équipe invalide, import annulé.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            if (!is_file($tempPath)) {
                $session->remove(self::SESSION_KEY);
                $this->addFlash('error', 'Fichier temporaire expiré, relance l\'import.');

                return $this->redirectToRoute('manager_rencontre_import_ffbb');
            }

            // Écriture réelle.
            try {
                $resultat = $this->importer->importFromFile($tempPath, $club, $equipe, (string) $data['saison'], false);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Erreur pendant l\'import : ' . $e->getMessage());

                return $this->redirectToRoute('manager_rencontre_import_ffbb_apercu');
            } finally {
                @unlink($tempPath);
                $session->remove(self::SESSION_KEY);
            }

            $this->addFlash('success', sprintf(
                '%d rencontre(s) importée(s) pour « %s ». %d déjà en base.',
                $resultat['stats']['creees'],
                $data['equipe_nom'],
                $resultat['stats']['deja_en_base']
            ));

            return $this->redirectToRoute('manager_rencontre_index');
        }

        return $this->render('manager/import/rencontres_apercu.html.twig', [
            'club'     => $club,
            'data'     => $data,
            'resultat' => $data['resultat'],
        ]);
    }
}
