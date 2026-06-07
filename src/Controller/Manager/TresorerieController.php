<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\OperationTresorerie;
use App\Repository\Sport\OperationTresorerieRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\TresorerieVoter;
use App\Service\JustificatifOperationUploader;
use App\Service\TresorerieExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller trésorerie — Bureau Phase D.1.
 *
 * Routé sur manager.mabb.fr.
 *
 * SÉCURITÉ :
 *   - Toutes les routes vérifient TresorerieVoter (TRESORIER + SUPER_ADMIN)
 *   - Tous les CRUD passent par le club actif (TenantResolver) → impossible
 *     de modifier une opération d'un autre club via l'URL.
 *   - CSRF token nominatif sur les actions destructives (delete).
 *
 * DESIGN :
 *   - Pas de formulaire Symfony Form pour D.1 — formulaires HTML directs.
 *     Plus simple à customiser, plus rapide à itérer. Si on standardise plus
 *     tard avec des Form Types, on refacto.
 *   - Validation manuelle dans le controller. Pour les règles complexes
 *     (montant max, libellé pattern...) on basculera vers Validator.
 */
class TresorerieController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly OperationTresorerieRepository $operationRepository,
        private readonly EntityManagerInterface $em,
        private readonly JustificatifOperationUploader $uploader,
        private readonly LoggerInterface $logger,
        private readonly TresorerieExporter $exporter,
    ) {}

    /**
     * Dashboard trésorerie — vue d'ensemble.
     *
     * Affiche : solde courant, flux du mois en cours, agrégation par catégorie,
     * graphe d'évolution 12 mois, et les 30 dernières opérations.
     */
    #[Route('/tresorerie', name: 'manager_tresorerie_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $club);

        // Plage "mois en cours"
        $debutMois = new \DateTimeImmutable('first day of this month 00:00:00');
        $finMois   = new \DateTimeImmutable('last day of this month 23:59:59');

        return $this->render('manager/tresorerie/dashboard.html.twig', [
            'club'              => $club,
            'solde'             => $this->operationRepository->getSolde($club),
            'recettes_mois'     => $this->operationRepository->sumByType($club, OperationTresorerie::TYPE_RECETTE, $debutMois, $finMois),
            'depenses_mois'     => $this->operationRepository->sumByType($club, OperationTresorerie::TYPE_DEPENSE, $debutMois, $finMois),
            'par_categorie'     => $this->operationRepository->sumByCategorie($club, $debutMois, $finMois),
            'evolution_12_mois' => $this->operationRepository->getEvolutionMensuelle($club, 12),
            'operations'        => $this->operationRepository->findByClub($club, 30),
        ]);
    }

    /**
     * Formulaire création + traitement d'une nouvelle opération.
     *
     * Pour D.1 on fait un seul handler GET (form vide) / POST (save).
     * Si la sauvegarde échoue, on réaffiche le form avec les données.
     */
    #[Route('/tresorerie/operations/nouvelle', name: 'manager_tresorerie_operation_new', methods: ['GET', 'POST'])]
    public function newOperation(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $club);

        $operation = new OperationTresorerie();
        $operation->setClub($club);
        // Par défaut : aujourd'hui, dépense, autres
        $operation->setDate(new \DateTimeImmutable());

        $errors = [];

        if ($request->isMethod('POST')) {
            $errors = $this->bindAndValidate($request, $operation);

            if (empty($errors)) {
                $user = $this->getUser();
                if ($user instanceof User) {
                    $operation->setCreatedBy($user);
                }

                // Upload justificatif si fourni
                /** @var UploadedFile|null $justificatif */
                $justificatif = $request->files->get('justificatif');
                if ($justificatif !== null) {
                    try {
                        // L'entité doit avoir un club avant l'upload (cf. service)
                        $this->em->persist($operation);
                        $this->em->flush(); // pour avoir un ID + le club bien rattaché
                        $this->uploader->upload($justificatif, $operation);
                        $this->em->flush(); // pour sauvegarder les champs justificatif
                    } catch (\Exception $e) {
                        $this->logger->error('Échec upload justificatif opération', [
                            'op_id' => $operation->getId(),
                            'error' => $e->getMessage(),
                        ]);
                        $errors[] = 'Justificatif refusé : ' . $e->getMessage();
                        // L'opération est déjà persistée — on revient sur la liste
                        // (justificatif manquant n'est pas bloquant).
                    }
                } else {
                    $this->em->persist($operation);
                    $this->em->flush();
                }

                if (empty(array_filter($errors, fn($e) => str_contains($e, 'Justificatif') === false))) {
                    $this->addFlash('success', sprintf('Opération « %s » enregistrée.', $operation->getLibelle()));
                    return $this->redirectToRoute('manager_tresorerie_dashboard');
                }
            }
        }

        return $this->render('manager/tresorerie/operation_form.html.twig', [
            'club'      => $club,
            'operation' => $operation,
            'errors'    => $errors,
            'is_new'    => true,
        ]);
    }

    /**
     * Détail d'une opération (avec édition inline).
     */
    #[Route('/tresorerie/operations/{id}', name: 'manager_tresorerie_operation_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function showOperation(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $operation = $this->operationRepository->find($id);
        if (!$operation || $operation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Opération introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $operation);

        $errors = [];

        // Édition POST
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $operation);
            $errors = $this->bindAndValidate($request, $operation);

            if (empty($errors)) {
                /** @var UploadedFile|null $justificatif */
                $justificatif = $request->files->get('justificatif');
                if ($justificatif !== null) {
                    try {
                        // Si déjà un justificatif, on supprime l'ancien physique
                        if ($operation->hasJustificatif()) {
                            $this->uploader->delete($operation);
                        }
                        $this->uploader->upload($justificatif, $operation);
                    } catch (\Exception $e) {
                        $errors[] = 'Justificatif refusé : ' . $e->getMessage();
                    }
                }

                if (empty($errors)) {
                    $this->em->flush();
                    $this->addFlash('success', 'Opération mise à jour.');
                    return $this->redirectToRoute('manager_tresorerie_operation_show', ['id' => $operation->getId()]);
                }
            }
        }

        return $this->render('manager/tresorerie/operation_form.html.twig', [
            'club'      => $club,
            'operation' => $operation,
            'errors'    => $errors,
            'is_new'    => false,
        ]);
    }

    /**
     * Suppression d'une opération (POST + CSRF).
     */
    #[Route('/tresorerie/operations/{id}/supprimer', name: 'manager_tresorerie_operation_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteOperation(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $operation = $this->operationRepository->find($id);
        if (!$operation || $operation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Opération introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $operation);

        // CSRF nominatif sur l'ID — empêche de supprimer une autre opération
        // avec un token valide pour une autre.
        if (!$this->isCsrfTokenValid('delete_operation_' . $operation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // Supprimer le justificatif physique avant le DELETE BDD
        if ($operation->hasJustificatif()) {
            $this->uploader->delete($operation);
        }

        $libelle = $operation->getLibelle();
        $this->em->remove($operation);
        $this->em->flush();

        $this->addFlash('success', sprintf('Opération « %s » supprimée.', $libelle));
        return $this->redirectToRoute('manager_tresorerie_dashboard');
    }

    /**
     * Sert un justificatif en streaming (anti hotlinking).
     *
     * Le fichier n'est PAS dans /public direct → un user ne peut pas le
     * deviner via URL. Le controller lit le path BDD, vérifie les droits,
     * et stream le fichier avec le bon Content-Type.
     */
    #[Route('/tresorerie/operations/{id}/justificatif', name: 'manager_tresorerie_justificatif', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serveJustificatif(int $id): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $operation = $this->operationRepository->find($id);
        if (!$operation || $operation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Opération introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $operation);

        $path = $this->uploader->getAbsolutePath($operation);
        if ($path === null) {
            throw $this->createNotFoundException('Justificatif introuvable.');
        }

        $response = new BinaryFileResponse($path);
        // Affichage inline (le navigateur tente d'afficher le PDF/image dans l'onglet)
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE,
            $operation->getJustificatifNomOriginal() ?? 'justificatif'
        );
        if ($operation->getJustificatifMimeType()) {
            $response->headers->set('Content-Type', $operation->getJustificatifMimeType());
        }
        return $response;
    }

    // ====================================================================
    // EXPORT CSV — Phase D.5
    // ====================================================================

    /**
     * Page de configuration de l'export : choisir période + filtres.
     */
    #[Route('/tresorerie/export', name: 'manager_tresorerie_export', methods: ['GET'])]
    public function exportPage(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $club);

        // Par défaut : exercice civil en cours (1er janvier → 31 décembre)
        $annee = (int) (new \DateTimeImmutable())->format('Y');

        return $this->render('manager/tresorerie/export.html.twig', [
            'club'        => $club,
            'debut_defaut' => sprintf('%d-01-01', $annee),
            'fin_defaut'   => sprintf('%d-12-31', $annee),
        ]);
    }

    /**
     * Téléchargement du fichier CSV — POST pour permettre le passage des filtres.
     *
     * Pourquoi POST et pas GET ?
     *   - GET : les paramètres restent en URL → l'historique navigateur garde
     *     un cache, et un refresh re-télécharge. Pas grave en soi mais sale.
     *   - POST : action explicite, pas dans l'historique de navigation.
     *   - Pas de CSRF token ici car l'action est LECTURE (pas de modification BDD).
     *     L'auth + voter suffisent.
     */
    #[Route('/tresorerie/export/csv', name: 'manager_tresorerie_export_csv', methods: ['POST'])]
    public function exportCsv(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $club);

        // Validation des paramètres
        try {
            $debut = new \DateTimeImmutable((string) $request->request->get('debut', ''));
            $fin   = new \DateTimeImmutable((string) $request->request->get('fin', ''));
        } catch (\Exception) {
            $this->addFlash('error', 'Dates invalides.');
            return $this->redirectToRoute('manager_tresorerie_export');
        }

        if ($debut > $fin) {
            $this->addFlash('error', 'La date de début doit être antérieure ou égale à la date de fin.');
            return $this->redirectToRoute('manager_tresorerie_export');
        }

        // S'assurer que la date de fin inclut toute la journée (jusqu'à 23h59)
        $fin = $fin->setTime(23, 59, 59);

        $type = $request->request->get('type');
        if ($type === '' || !in_array($type, OperationTresorerie::TYPES, true)) {
            $type = null;
        }
        $categorie = $request->request->get('categorie');
        if ($categorie === '' || !in_array($categorie, OperationTresorerie::CATEGORIES, true)) {
            $categorie = null;
        }

        // Génération du CSV via le service dédié
        $contenu = $this->exporter->exporterOperations($club, $debut, $fin, $type, $categorie);
        $filename = $this->exporter->nomFichier($club, $debut, $fin);

        $this->logger->info('Export CSV trésorerie', [
            'club_id'   => $club->getId(),
            'debut'     => $debut->format('Y-m-d'),
            'fin'       => $fin->format('Y-m-d'),
            'type'      => $type,
            'categorie' => $categorie,
            'taille'    => strlen($contenu),
        ]);

        $response = new Response($contenu);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        // makeDisposition échappe correctement les caractères spéciaux dans le filename
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename
            )
        );
        return $response;
    }

    // ====================================================================
    // PRIVÉ — Liaison form + validation
    // ====================================================================

    /**
     * Bind les champs POST sur l'entité et valide.
     * Retourne un array d'erreurs (vide si tout OK).
     *
     * @return string[]
     */
    private function bindAndValidate(Request $request, OperationTresorerie $operation): array
    {
        $errors = [];

        // Type
        $type = (string) $request->request->get('type', OperationTresorerie::TYPE_DEPENSE);
        if (!in_array($type, OperationTresorerie::TYPES, true)) {
            $errors[] = 'Type d\'opération invalide.';
            return $errors;
        }
        $operation->setType($type);

        // Catégorie (doit être compatible avec le type)
        $categorie = (string) $request->request->get('categorie', '');
        if (!in_array($categorie, OperationTresorerie::CATEGORIES, true)) {
            $errors[] = 'Catégorie invalide.';
            return $errors;
        }
        $operation->setCategorie($categorie);
        if (!$operation->isCategorieValidePourType()) {
            $errors[] = sprintf(
                'La catégorie « %s » n\'est pas valide pour une %s.',
                $operation->getCategorieLabel(),
                $type === OperationTresorerie::TYPE_RECETTE ? 'recette' : 'dépense'
            );
        }

        // Montant
        $montantRaw = trim((string) $request->request->get('montant', ''));
        // Tolérer "12,34" (virgule française) → on convertit en point
        $montantRaw = str_replace(',', '.', $montantRaw);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $montantRaw)) {
            $errors[] = 'Montant invalide. Format attendu : 12.34';
        } elseif ((float) $montantRaw <= 0) {
            $errors[] = 'Le montant doit être strictement positif.';
        } else {
            // Forcer 2 décimales pour stockage propre
            $operation->setMontant(number_format((float) $montantRaw, 2, '.', ''));
        }

        // Date
        $dateRaw = (string) $request->request->get('date', '');
        try {
            $date = new \DateTimeImmutable($dateRaw);
            $operation->setDate($date);
        } catch (\Exception) {
            $errors[] = 'Date invalide.';
        }

        // Libellé
        $libelle = trim((string) $request->request->get('libelle', ''));
        if ($libelle === '') {
            $errors[] = 'Le libellé est obligatoire.';
        } elseif (mb_strlen($libelle) > 255) {
            $errors[] = 'Libellé trop long (255 caractères max).';
        } else {
            $operation->setLibelle($libelle);
        }

        // Notes (optionnelles)
        $notes = trim((string) $request->request->get('notes', ''));
        $operation->setNotes($notes !== '' ? $notes : null);

        return $errors;
    }
}
