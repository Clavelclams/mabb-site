<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use App\Repository\Core\RgpdRequestRepository;
use App\Service\Rgpd\RgpdAnonymizer;
use App\Service\Rgpd\RgpdExporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * B2 — Admin : pilotage des demandes RGPD (Art. 15 export, Art. 17 effacement).
 *
 * Accès : ROLE_SUPER_ADMIN uniquement.
 *
 * Workflow effacement :
 *   - Liste des demandes en attente
 *   - Valider → exécute RgpdAnonymizer (irréversible) → statut=effectuee
 *   - Refuser → motif obligatoire → statut=refusee
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminRgpdController extends AbstractController
{
    public function __construct(
        private readonly RgpdRequestRepository $rgpdRepo,
        private readonly RgpdAnonymizer $anonymizer,
        private readonly RgpdExporter $exporter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/admin/rgpd', name: 'admin_rgpd_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/rgpd/index.html.twig', [
            'pending' => $this->rgpdRepo->findPending(),
            'recent'  => $this->rgpdRepo->findRecent(30),
        ]);
    }

    #[Route('/admin/rgpd/{id}/valider', name: 'admin_rgpd_valider', methods: ['POST'])]
    public function valider(Request $request, RgpdRequest $req): Response
    {
        if (!$this->isCsrfTokenValid('rgpd_valider_' . $req->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rgpd_index');
        }

        if (!$req->isPending()) {
            $this->addFlash('warning', 'Cette demande a déjà été traitée.');
            return $this->redirectToRoute('admin_rgpd_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $user  = $req->getUser();

        if ($user === null) {
            $this->addFlash('error', 'User introuvable.');
            return $this->redirectToRoute('admin_rgpd_index');
        }

        // 1. Marque la demande comme validée
        $req->valider($admin);

        // 2. Exécute l'anonymisation (effacement effectif)
        if ($req->getType() === RgpdRequest::TYPE_EFFACEMENT) {
            $result = $this->anonymizer->anonymizeUser($user);
            $req->marquerEffectuee();
            $this->em->flush();

            $this->logger->warning('RGPD effacement effectué', [
                'rgpd_id'    => $req->getId(),
                'admin_id'   => $admin->getId(),
                'user_id'    => $result['user_id'],
            ]);

            $this->addFlash('success', sprintf(
                '✅ Compte anonymisé : %s. Effacement irréversible.',
                $result['anonymized_email']
            ));
        } else {
            // Type export — on log mais l'export se télécharge ailleurs
            $req->marquerEffectuee();
            $this->em->flush();
            $this->addFlash('success', 'Demande d\'export validée.');
        }

        return $this->redirectToRoute('admin_rgpd_index');
    }

    #[Route('/admin/rgpd/{id}/refuser', name: 'admin_rgpd_refuser', methods: ['POST'])]
    public function refuser(Request $request, RgpdRequest $req): Response
    {
        if (!$this->isCsrfTokenValid('rgpd_refuser_' . $req->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin_rgpd_index');
        }

        $motif = trim((string) $request->request->get('motif_admin', ''));
        if ($motif === '') {
            $this->addFlash('error', 'Le motif de refus est obligatoire.');
            return $this->redirectToRoute('admin_rgpd_index');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $req->refuser($admin, $motif);
        $this->em->flush();

        $this->logger->info('RGPD demande refusée', [
            'rgpd_id'  => $req->getId(),
            'admin_id' => $admin->getId(),
            'motif'    => $motif,
        ]);

        $this->addFlash('warning', 'Demande refusée. Le user sera informé.');
        return $this->redirectToRoute('admin_rgpd_index');
    }

    /**
     * Export JSON des données d'un user (admin l'autorise après validation).
     */
    #[Route('/admin/rgpd/{userId}/export.json', name: 'admin_rgpd_export', methods: ['GET'])]
    public function export(int $userId): JsonResponse
    {
        $user = $this->em->find(User::class, $userId);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        $data = $this->exporter->exportUserData($user);

        $response = new JsonResponse($data);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="mabb_rgpd_user_%d_%s.json"',
            $userId,
            (new \DateTimeImmutable())->format('Y-m-d')
        ));
        return $response;
    }
}
