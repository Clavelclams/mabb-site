<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EvaluationMatchRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Voter\ClubVoter;
use App\Service\Import\EvaluationMatchXlsxExporter;
use App\Service\Import\EvaluationMatchXlsxImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * EvaluationMatchController — saisie batch des évals d'un match.
 *
 * WORKFLOW UTILISATEUR :
 *   1. Coach va sur la page d'une rencontre (manager_rencontre_show)
 *   2. Clique sur "Saisir les évals" (visible si CLUB_STAFF)
 *   3. Voit un tableau avec une ligne par joueuse active de l'équipe
 *   4. Remplit les compteurs (minutes, tirs, rebonds, passes, etc.)
 *   5. L'eval FIBA se met à jour en live via JS pour feedback
 *   6. Submit unique → toutes les évals sont créées/mises à jour
 *
 * SÉCURITÉ :
 *   - CLUB_STAFF requis sur la rencontre (le ClubVoter protège via getClub())
 *   - CSRF nominatif lié à la rencontre (évite replay)
 *   - Validation côté serveur : tous les compteurs >= 0
 *   - Pas de création d'éval "vide" : si tous les compteurs = 0 ET minutes = 0,
 *     on skip (évite de polluer la BDD avec des évals fantômes)
 *
 * STRATÉGIE BATCH :
 *   On charge TOUTES les évals existantes en une seule requête (evaluationsRencontre),
 *   puis on UPDATE celles qui existent et INSERT celles qui n'existent pas.
 *   Évite N+1 queries. Un seul flush en fin de boucle.
 */
class EvaluationMatchController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationMatchRepository $evaluationMatchRepository,
        private readonly JoueurRepository $joueurRepository,
    ) {}

    /**
     * Page de saisie des évals d'une rencontre (GET = afficher / POST = sauvegarder).
     *
     *   GET  manager.mabb.fr/rencontres/{id}/evals
     *   POST manager.mabb.fr/rencontres/{id}/evals
     */
    #[Route('/rencontres/{id}/evals', name: 'manager_rencontre_evals', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function saisir(Request $request, Rencontre $rencontre): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        // === 1. Récupère les joueuses ACTIVES de l'équipe (en attente d'éval ou déjà saisies) ===
        $joueuses = $this->joueurRepository->findBy(
            ['equipe' => $rencontre->getEquipe(), 'isActive' => true],
            ['nom' => 'ASC', 'prenom' => 'ASC']
        );

        // === 2. Charge les évals existantes pour cette rencontre (1 seule requête, évite N+1) ===
        $evalsExistantes = $this->evaluationMatchRepository->evaluationsRencontre($rencontre);
        // Indexation par joueur_id pour lookup O(1) dans la boucle d'affichage et de save
        $evalsParJoueur = [];
        foreach ($evalsExistantes as $e) {
            $evalsParJoueur[$e->getJoueur()->getId()] = $e;
        }

        // === 3. POST : traitement du batch ===
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('evals_rencontre_' . $rencontre->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
            }

            $donnees = $request->request->all('evals');
            // Structure attendue : ['12' => ['minutes_jouees' => 10, 'tirs2pts_reussis' => 3, ...], ...]
            // Clé = joueur_id (string car vient du form), valeur = compteurs

            $nbSaisies = 0;
            $nbSkipped = 0;

            foreach ($joueuses as $joueur) {
                $donneesJoueur = $donnees[(string) $joueur->getId()] ?? null;
                if (!is_array($donneesJoueur)) {
                    continue;
                }

                // Récupère l'éval existante ou en crée une nouvelle
                $eval = $evalsParJoueur[$joueur->getId()] ?? null;
                $estNouvelle = ($eval === null);

                // Si "skip" coché (case à cocher "ne pas créer d'éval") → on supprime l'existante si présente
                if (isset($donneesJoueur['skip'])) {
                    if ($eval !== null) {
                        $this->em->remove($eval);
                        $nbSkipped++;
                    }
                    continue;
                }

                if ($estNouvelle) {
                    $eval = new EvaluationMatch();
                    $eval->setJoueur($joueur);
                    $eval->setRencontre($rencontre);
                }

                // Application des données avec validation min/max (cast int + clamp à 0+)
                $eval->setIsStarter(isset($donneesJoueur['is_starter']));
                $eval->setMinutesJouees($this->clampInt($donneesJoueur['minutes_jouees'] ?? 0, 0, 60));
                $eval->setTirs2ptsReussis($this->clampInt($donneesJoueur['tirs2pts_reussis'] ?? 0));
                $eval->setTirs2ptsTentes($this->clampInt($donneesJoueur['tirs2pts_tentes'] ?? 0));
                $eval->setTirs3ptsReussis($this->clampInt($donneesJoueur['tirs3pts_reussis'] ?? 0));
                $eval->setTirs3ptsTentes($this->clampInt($donneesJoueur['tirs3pts_tentes'] ?? 0));
                $eval->setLancersReussis($this->clampInt($donneesJoueur['lancers_reussis'] ?? 0));
                $eval->setLancersTentes($this->clampInt($donneesJoueur['lancers_tentes'] ?? 0));
                $eval->setRebondsOffensifs($this->clampInt($donneesJoueur['rebonds_offensifs'] ?? 0));
                $eval->setRebondsDefensifs($this->clampInt($donneesJoueur['rebonds_defensifs'] ?? 0));
                $eval->setPassesDecisives($this->clampInt($donneesJoueur['passes_decisives'] ?? 0));
                $eval->setInterceptions($this->clampInt($donneesJoueur['interceptions'] ?? 0));
                $eval->setContres($this->clampInt($donneesJoueur['contres'] ?? 0));
                $eval->setContresSubis($this->clampInt($donneesJoueur['contres_subis'] ?? 0));
                $eval->setFautesCommises($this->clampInt($donneesJoueur['fautes_commises'] ?? 0));
                $eval->setFautesProvoquees($this->clampInt($donneesJoueur['fautes_provoquees'] ?? 0));
                $eval->setPertesBalle($this->clampInt($donneesJoueur['pertes_balle'] ?? 0));

                // Notes coach : trim + null si vide pour ne pas polluer la BDD
                $notes = trim((string) ($donneesJoueur['notes_coach'] ?? ''));
                $eval->setNotesCoach($notes !== '' ? $notes : null);

                // Cohérence : tirs réussis ne peuvent pas dépasser tentés
                if ($eval->getTirs2ptsReussis() > $eval->getTirs2ptsTentes()) {
                    $eval->setTirs2ptsTentes($eval->getTirs2ptsReussis());
                }
                if ($eval->getTirs3ptsReussis() > $eval->getTirs3ptsTentes()) {
                    $eval->setTirs3ptsTentes($eval->getTirs3ptsReussis());
                }
                if ($eval->getLancersReussis() > $eval->getLancersTentes()) {
                    $eval->setLancersTentes($eval->getLancersReussis());
                }

                // Filtre : ne pas créer d'éval "vide" (toutes les stats à 0 ET minutes à 0)
                if ($estNouvelle && $this->estVide($eval)) {
                    continue;
                }

                if ($estNouvelle) {
                    $this->em->persist($eval);
                }
                $nbSaisies++;
            }

            $this->em->flush();

            $this->addFlash('success', sprintf(
                '%d éval%s enregistrée%s%s.',
                $nbSaisies,
                $nbSaisies > 1 ? 's' : '',
                $nbSaisies > 1 ? 's' : '',
                $nbSkipped > 0 ? sprintf(' (%d supprimée%s)', $nbSkipped, $nbSkipped > 1 ? 's' : '') : ''
            ));

            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // === 4. GET : affichage du formulaire ===
        return $this->render('manager/evaluation/saisie.html.twig', [
            'rencontre'      => $rencontre,
            'joueuses'       => $joueuses,
            'evals_par_joueur' => $evalsParJoueur,
        ]);
    }

    /**
     * Cast en int et garantit que la valeur est dans [min, max].
     * Évite les négatifs (impossible en basket) et les valeurs absurdes.
     */
    private function clampInt(mixed $value, int $min = 0, int $max = 999): int
    {
        $i = (int) $value;
        if ($i < $min) return $min;
        if ($i > $max) return $max;
        return $i;
    }

    /**
     * Une éval est "vide" si toutes ses stats sont à 0 ET minutes = 0.
     * Sert à ne pas polluer la BDD avec des évals créées par erreur.
     */
    private function estVide(EvaluationMatch $e): bool
    {
        return $e->getMinutesJouees() === 0
            && $e->getTirs2ptsTentes() === 0
            && $e->getTirs3ptsTentes() === 0
            && $e->getLancersTentes() === 0
            && $e->getRebondsOffensifs() === 0
            && $e->getRebondsDefensifs() === 0
            && $e->getPassesDecisives() === 0
            && $e->getInterceptions() === 0
            && $e->getContres() === 0
            && $e->getContresSubis() === 0
            && $e->getFautesCommises() === 0
            && $e->getFautesProvoquees() === 0
            && $e->getPertesBalle() === 0
            && $e->getNotesCoach() === null;
    }

    /**
     * [B22b-bis V2 — 15/06/2026] Téléchargement du template Excel pré-rempli.
     *
     *   GET /rencontres/{id}/evals/template.xlsx
     *
     * Le coach utilise ce template pour saisir les stats sur son PC (workflow
     * rapide avec copier-coller depuis le PDF FFBB visualisé à côté), puis
     * uploade le fichier rempli via importXlsx().
     */
    #[Route(
        '/rencontres/{id}/evals/template.xlsx',
        name: 'manager_rencontre_evals_template',
        methods: ['GET'],
        requirements: ['id' => '\d+']
    )]
    public function downloadTemplate(Rencontre $rencontre, EvaluationMatchXlsxExporter $exporter): BinaryFileResponse
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $filePath = $exporter->exportToTempFile($rencontre);

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $exporter->suggestedFilename($rencontre),
        );
        // Le fichier temp sera supprimé par le serveur après envoi
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * [B22b-bis V2 — 15/06/2026] Upload + parsing du fichier Excel rempli.
     *
     *   POST /rencontres/{id}/evals/import-xlsx  (avec file=...)
     *
     * Sécurité :
     *   - CLUB_STAFF requis (même niveau que la saisie manuelle)
     *   - CSRF nominatif
     *   - Validation MIME du fichier (.xlsx uniquement)
     *   - Toutes les erreurs ligne par ligne sont affichées dans la flash
     *
     * Idempotent : ré-importer le même fichier UPDATE les EvaluationMatch existants.
     */
    #[Route(
        '/rencontres/{id}/evals/import-xlsx',
        name: 'manager_rencontre_evals_import_xlsx',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function importXlsx(
        Request $request,
        Rencontre $rencontre,
        EvaluationMatchXlsxImporter $importer,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('import_xlsx_evals_' . $rencontre->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('xlsx_file');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Aucun fichier reçu ou fichier invalide.');
            return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
        }

        // Vérif extension
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'xlsx') {
            $this->addFlash('error', sprintf('Format non supporté (.%s). Utilise un fichier .xlsx (Excel/LibreOffice).', $ext));
            return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
        }

        // Vérif taille (max 5 Mo, large mais raisonnable)
        if ($file->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('error', 'Fichier trop volumineux (max 5 Mo).');
            return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
        }

        $result = $importer->importFromFile($file->getRealPath(), $rencontre);

        // Récap visuel
        $msg = sprintf(
            '✓ Import terminé : %d créée(s), %d mise(s) à jour, %d ignorée(s).',
            $result['created'],
            $result['updated'],
            $result['skipped'],
        );
        $this->addFlash('success', $msg);

        // Erreurs ligne par ligne
        foreach ($result['errors'] as $err) {
            $this->addFlash('warning', $err);
        }

        return $this->redirectToRoute('manager_rencontre_evals', ['id' => $rencontre->getId()]);
    }
}
