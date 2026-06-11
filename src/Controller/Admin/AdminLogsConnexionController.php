<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\Core\ConnexionLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * B2 — Admin : consulter les logs de connexion (succès + échecs).
 *
 * Accès : ROLE_SUPER_ADMIN uniquement (super-admin du site, pas un
 * dirigeant de club — c'est un log technique transverse).
 *
 * Route préfixée /admin par config/routes/admin.yaml (host mabb.fr).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
class AdminLogsConnexionController extends AbstractController
{
    public function __construct(
        private readonly ConnexionLogRepository $logRepo,
    ) {}

    #[Route('/admin/logs-connexion', name: 'admin_logs_connexion', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;

        $filters = [
            'ip'       => trim((string) $request->query->get('ip', '')) ?: null,
            'email'    => trim((string) $request->query->get('email', '')) ?: null,
            'succes'   => $request->query->get('succes', null),
            'contexte' => trim((string) $request->query->get('contexte', '')) ?: null,
        ];

        $result = $this->logRepo->paginate($page, $perPage, $filters);

        return $this->render('admin/logs_connexion/index.html.twig', [
            'logs'       => $result['logs'],
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages' => (int) ceil($result['total'] / $perPage),
            'filters'    => $filters,
        ]);
    }
}
