<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionConvocation;
use App\Entity\Sport\ReunionDocument;
use App\Entity\Sport\ReunionPvVersion;
use App\Repository\Sport\ReunionConvocationRepository;
use App\Repository\Sport\ReunionRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use App\Service\ReunionDocumentUploader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ReunionController — gestion des réunions du bureau d'un club.
 *
 * RÈGLES D'ACCÈS :
 *   - Liste/voir une réunion :     CLUB_MEMBER (tout membre voit les réunions du club)
 *   - Créer/éditer une réunion :   CLUB_STAFF  (staff = secrétaire/président/coach)
 *   - Saisir le PV / changer statut : CLUB_STAFF
 *
 * UX :
 *   - Lors de la création, le créateur peut convoquer par ROLE (ex: tous les
 *     DIRIGEANT + COACH) ou ajouter des USERS individuels.
 *   - Après la réunion, le secrétaire passe le statut à TENUE et saisit le PV.
 *   - Quand le PV est saisi, les convoqués voient "Nouveau PV à lire" sur leur
 *     dashboard Manager (Phase C).
 *
 * MULTI-TENANT : chaque action vérifie que la réunion appartient au club actif
 * via Reunion::getClub() + ClubVoter.
 */
class ReunionController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly EntityManagerInterface $em,
        private readonly ReunionRepository $reunionRepository,
        private readonly ReunionConvocationRepository $convocationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste des réunions du club (récentes d'abord).
     *
     * VISIBILITÉ (Phase F) :
     *   - STAFF/COACH/DIRIGEANT : toutes les réunions du club
     *   - MEMBER simple (BENEVOLE/JOUEUR/PARENT/EMPLOYE) : seulement les réunions
     *     où il est convoqué OU dont la synthèse est publiée publiquement.
     */
    #[Route('/reunions', name: 'manager_reunion_index', methods: ['GET'])]
    public function index(): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            $this->addFlash('warning', 'Aucun club actif.');
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $club);

        // Staff voit tout — Member simple voit ce qui le concerne
        $estStaff = $this->isGranted(ClubVoter::CLUB_STAFF, $club);
        if ($estStaff) {
            $reunions = $this->reunionRepository->findByClub($club);
        } else {
            // Member non-staff : on charge toutes les réunions du club PUIS on filtre en PHP
            // selon (convoqué OU synthèse_visible_roles contient un de ses rôles).
            // Filtrage en PHP car JSON_CONTAINS pas portable / lourd à maintenir en DQL.
            $user = $this->getUser();
            $rolesUser = [];
            if ($user instanceof User) {
                foreach ($user->getUserClubRoles() as $ucr) {
                    if ($ucr->getClub()?->getId() === $club->getId() && $ucr->isStatusActive()) {
                        $rolesUser[] = $ucr->getRole();
                    }
                }
            }

            $toutes = $this->reunionRepository->findByClub($club);
            $reunions = array_values(array_filter($toutes, function (Reunion $r) use ($user, $rolesUser) {
                // Convoqué ?
                foreach ($r->getConvocations() as $c) {
                    if ($c->getUser()?->getId() === $user?->getId()) return true;
                }
                // Synthèse publiée pour au moins un de ses rôles ?
                return $r->syntheseVisibleA($rolesUser);
            }));
        }

        return $this->render('manager/reunion/index.html.twig', [
            'club'     => $club,
            'reunions' => $reunions,
        ]);
    }

    /**
     * Création d'une nouvelle réunion.
     */
    #[Route('/reunions/nouvelle', name: 'manager_reunion_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            return $this->redirectToRoute('manager_dashboard');
        }
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $club);

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('new_reunion_' . $club->getId(), $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('manager_reunion_new');
            }

            try {
                $reunion = new Reunion();
                $reunion->setClub($club);
                $reunion->setTitre(trim((string) $request->request->get('titre', '')));
                $reunion->setType((string) $request->request->get('type', Reunion::TYPE_CA));

                $dateStr = (string) $request->request->get('date', '');
                if ($dateStr === '') {
                    throw new \InvalidArgumentException('La date est obligatoire.');
                }
                $reunion->setDate(new \DateTimeImmutable($dateStr));
                $reunion->setLieu(trim((string) $request->request->get('lieu', '')) ?: null);
                $reunion->setOrdreDuJour(trim((string) $request->request->get('ordreDuJour', '')));
                $reunion->setCreateur($this->getUser() instanceof User ? $this->getUser() : null);

                $this->em->persist($reunion);

                // === Convocation par rôle (cases à cocher) ===
                // Récupère les UCR ACTIFS du club pour les rôles sélectionnés
                $rolesConvoques = $request->request->all('rolesConvoques');
                if (is_array($rolesConvoques) && !empty($rolesConvoques)) {
                    $ucrs = $this->em->getRepository(UserClubRole::class)
                        ->createQueryBuilder('ucr')
                        ->where('ucr.club = :club')
                        ->andWhere('ucr.status = :active')
                        ->andWhere('ucr.role IN (:roles)')
                        ->setParameter('club', $club)
                        ->setParameter('active', UserClubRole::STATUS_ACTIVE)
                        ->setParameter('roles', $rolesConvoques)
                        ->getQuery()->getResult();

                    $usersConvoques = [];
                    foreach ($ucrs as $ucr) {
                        $u = $ucr->getUser();
                        if ($u !== null) {
                            $usersConvoques[$u->getId()] = $u; // dédoublonnage si user a plusieurs rôles
                        }
                    }

                    foreach ($usersConvoques as $u) {
                        $conv = new ReunionConvocation();
                        $conv->setReunion($reunion);
                        $conv->setUser($u);
                        $this->em->persist($conv);
                    }
                }

                $this->em->flush();

                $this->logger->info('Reunion créée', [
                    'reunion_id' => $reunion->getId(),
                    'titre'      => $reunion->getTitre(),
                    'type'       => $reunion->getType(),
                    'club'       => $club->getNom(),
                    'auteur'     => $this->getUser()?->getUserIdentifier(),
                ]);

                $this->addFlash('success', sprintf('✅ Réunion "%s" créée — %d convoqués.', $reunion->getTitre(), $reunion->getConvocations()->count()));
                return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);

            } catch (\Throwable $e) {
                $this->logger->error('Erreur création réunion', ['exception' => $e->getMessage()]);
                $this->addFlash('error', '❌ ' . $e->getMessage());
            }
        }

        return $this->render('manager/reunion/new.html.twig', [
            'club'         => $club,
            'types'        => Reunion::TYPE_LIBELLES,
            'roles_dispo'  => UserClubRole::ROLES_DISPONIBLES,
        ]);
    }

    /**
     * Vue détaillée d'une réunion + saisie du PV.
     */
    #[Route('/reunions/{id}', name: 'manager_reunion_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Reunion $reunion): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $reunion);

        // Marque le PV comme lu pour l'user courant (si convoqué et PV existant)
        if ($reunion->hasPv() && $this->getUser() instanceof User) {
            $conv = $this->convocationRepository->findOneByReunionAndUser($reunion, $this->getUser());
            if ($conv !== null && !$conv->isPvLu()) {
                $conv->marquerPvLu();
                $this->em->flush();
            }
        }

        return $this->render('manager/reunion/show.html.twig', [
            'reunion' => $reunion,
        ]);
    }

    /**
     * Saisie/édition du PV (et passage automatique en statut TENUE).
     *
     * SUIVI HISTORIQUE (Phase F) : avant d'écraser le PV, on snapshote l'ancienne
     * valeur dans reunion_pv_version. Permet de remonter dans l'historique :
     * "qui a écrit quoi, quand". Aucune suppression hard — uniquement archivage.
     */
    #[Route('/reunions/{id}/pv', name: 'manager_reunion_pv', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function savePv(Request $request, Reunion $reunion): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $reunion);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('pv_reunion_' . $reunion->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        $nouveauPv = trim((string) $request->request->get('pvContenu', ''));
        $ancienPv  = $reunion->getPvContenu();

        // === SNAPSHOT (Phase F) : archive l'ancien PV s'il existait et qu'il change ===
        if ($ancienPv !== null && trim($ancienPv) !== '' && $ancienPv !== $nouveauPv) {
            $version = new ReunionPvVersion();
            $version->setReunion($reunion);
            $version->setContenuSnapshot($ancienPv);
            $version->setModifiePar($this->getUser() instanceof User ? $this->getUser() : null);
            $this->em->persist($version);
        }

        $reunion->setPvContenu($nouveauPv !== '' ? $nouveauPv : null);

        // Si on saisit un PV et que la réunion était planifiée → on passe à TENUE
        if ($nouveauPv !== '' && $reunion->isPlanifiee()) {
            $reunion->setStatut(Reunion::STATUT_TENUE);
        }

        $this->em->flush();

        $this->addFlash('success', '✅ PV enregistré.');
        return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
    }

    /**
     * Enregistre la synthèse publique + le sélecteur granulaire des rôles autorisés à la voir.
     *
     * Sélecteur granulaire (Phase F.2) :
     *   - Aucun rôle coché → synthèse en brouillon, seul le staff la voit
     *   - Rôles cochés → synthèse visible aux users ayant un de ces rôles dans le club
     */
    #[Route('/reunions/{id}/synthese', name: 'manager_reunion_synthese', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveSynthese(Request $request, Reunion $reunion): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $reunion);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('synthese_' . $reunion->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        $synthese = trim((string) $request->request->get('synthesePublique', ''));
        $reunion->setSynthesePublique($synthese !== '' ? $synthese : null);

        // === Récupération des rôles cochés (whitelist contre injection) ===
        $rolesPostes = $request->request->all('rolesVisibles');
        $rolesValides = [];
        if (is_array($rolesPostes)) {
            foreach ($rolesPostes as $role) {
                if (in_array($role, UserClubRole::ROLES_DISPONIBLES, true)) {
                    $rolesValides[] = $role;
                }
            }
        }
        // Si pas de synthèse, on force liste vide (cohérence)
        $reunion->setSyntheseVisibleRoles($synthese !== '' && !empty($rolesValides) ? $rolesValides : null);

        $this->em->flush();

        if (empty($rolesValides) || $synthese === '') {
            $this->addFlash('success', '✅ Synthèse enregistrée (brouillon — non publiée).');
        } else {
            $this->addFlash('success', sprintf('✅ Synthèse publiée pour : %s.', implode(', ', $rolesValides)));
        }

        return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
    }

    // ====================================================================
    // DOCUMENTS ATTACHÉS À UNE RÉUNION (Phase F)
    // ====================================================================

    /**
     * Upload d'un document attaché à une réunion.
     */
    #[Route('/reunions/{id}/documents', name: 'manager_reunion_document_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadDocument(
        Request $request,
        Reunion $reunion,
        ReunionDocumentUploader $uploader,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $reunion);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('upload_doc_' . $reunion->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('document');
        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        try {
            $doc = $uploader->upload($file, $reunion);
            $doc->setUploadePar($this->getUser() instanceof User ? $this->getUser() : null);
            $this->em->persist($doc);
            $this->em->flush();

            $this->addFlash('success', sprintf('✅ Document "%s" ajouté.', $doc->getNomOriginal()));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (FileException $e) {
            $this->addFlash('error', 'Impossible d\'enregistrer le fichier sur le serveur.');
        }

        return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
    }

    /**
     * Suppression d'un document attaché.
     */
    #[Route('/reunions/documents/{id}/supprimer', name: 'manager_reunion_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteDocument(
        Request $request,
        ReunionDocument $document,
        ReunionDocumentUploader $uploader,
    ): Response {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $document);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_doc_' . $document->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $document->getReunion()?->getId() ?? 0]);
        }

        $reunionId = $document->getReunion()?->getId();
        $uploader->delete($document);
        $this->em->remove($document);
        $this->em->flush();

        $this->addFlash('success', '✅ Document supprimé.');
        return $this->redirectToRoute('manager_reunion_show', ['id' => $reunionId ?? 0]);
    }

    /**
     * Sert un document en streaming avec ClubVoter (anti-fuite multi-tenant).
     */
    #[Route('/reunions/documents/{id}/voir', name: 'manager_reunion_document_serve', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function serveDocument(
        ReunionDocument $document,
        ReunionDocumentUploader $uploader,
    ): Response {
        // CLUB_MEMBER suffit (lecture) — pas besoin d'être staff
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_MEMBER, $document);

        $absolutePath = $uploader->getAbsolutePath($document);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Document introuvable.');
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->headers->set('Content-Type', $document->getMimeType() ?? 'application/octet-stream');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $document->getNomOriginal() ?? 'document'
        );
        return $response;
    }

    /**
     * Changement de statut (annuler / réactiver).
     */
    #[Route('/reunions/{id}/statut', name: 'manager_reunion_statut', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function changeStatut(Request $request, Reunion $reunion): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $reunion);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('statut_reunion_' . $reunion->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        $nouveauStatut = (string) $request->request->get('statut', '');
        if (!in_array($nouveauStatut, Reunion::STATUTS, true)) {
            $this->addFlash('error', 'Statut invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
        }

        $reunion->setStatut($nouveauStatut);
        $this->em->flush();

        $this->addFlash('success', sprintf('✅ Statut → %s', $nouveauStatut));
        return $this->redirectToRoute('manager_reunion_show', ['id' => $reunion->getId()]);
    }

    /**
     * Saisie de la présence d'un convoqué (présent/excusé/absent).
     * Appelé en POST AJAX ou form classique.
     */
    #[Route('/reunions/convocation/{id}/presence', name: 'manager_reunion_presence', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function presence(Request $request, ReunionConvocation $convocation): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $convocation);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('presence_' . $convocation->getId(), $token)) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $convocation->getReunion()->getId()]);
        }

        $nouveauStatut = (string) $request->request->get('statut', '');
        if (!in_array($nouveauStatut, ReunionConvocation::STATUTS, true)) {
            $this->addFlash('error', 'Statut de présence invalide.');
            return $this->redirectToRoute('manager_reunion_show', ['id' => $convocation->getReunion()->getId()]);
        }

        $convocation->setStatut($nouveauStatut);
        $this->em->flush();

        return $this->redirectToRoute('manager_reunion_show', ['id' => $convocation->getReunion()->getId()]);
    }
}
