<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Repository\Core\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SsoController — [UX V1, 10/07/2026] connexion UNIQUE app → web.
 *
 * LE PROBLÈME (retour terrain) : la joueuse se connecte dans l'app (Bearer),
 * puis ouvre une page web en WebView (shot chart, convocations…) → la
 * WebView a SES cookies, vides → deuxième écran de connexion. Vécu :
 * « je me connecte deux fois », inacceptable pour la sortie store.
 *
 * LA SOLUTION (ticket signé, sans état, sans table) :
 *  1. POST /api/pirb/sso/ticket (Bearer)  → l'app reçoit un ticket signé
 *     HMAC contenant { userId, expiration 90 s }.
 *  2. GET /sso/pirb?ticket=…&cible=/stats/shotchart (PUBLIC, host pirb) →
 *     le serveur vérifie la signature + l'expiration, connecte l'utilisateur
 *     sur le firewall web `pirb` (cookie de session posé dans la WebView),
 *     puis redirige vers la page demandée. Les WebViews suivantes de l'app
 *     partagent ce cookie → plus JAMAIS de deuxième login.
 *
 * SÉCURITÉ :
 *  - signature HMAC-SHA256 avec %kernel.secret% : infalsifiable sans le
 *    secret serveur ; pas de table (stateless), rien à purger ;
 *  - durée de vie 90 s : un ticket volé dans un log expire immédiatement ;
 *    (pas de one-time strict en V1 — l'ajouter demanderait un stockage ;
 *    90 s + HTTPS + usage immédiat par la WebView couvrent le besoin) ;
 *  - `cible` STRICTEMENT locale (doit commencer par « / », pas « // ») :
 *    impossible de rediriger vers un site externe (anti-open-redirect).
 */
class SsoController extends AbstractController
{
    private const VALIDITE_S = 90;

    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly Security $security,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /sso/pirb — la WebView consomme le ticket → session web posée.
    // (Route PUBLIQUE sur pirb.mabb.fr : voir access_control. Le host est
    // appliqué par l'import config/routes/pirb.yaml, comme tout Pirb/.
    // L'émission du ticket vit côté API : Api\PirbSsoTicketController.)
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/sso/pirb', name: 'pirb_sso_consume', methods: ['GET'])]
    public function consommer(Request $request): Response
    {
        $ticket = (string) $request->query->get('ticket', '');
        $cible  = (string) $request->query->get('cible', '/');

        // Cible STRICTEMENT locale : commence par « / » mais pas « // »
        // (« //evil.com » serait interprété comme une URL absolue). Sinon
        // on retombe sur l'accueil — jamais d'open redirect.
        if (!str_starts_with($cible, '/') || str_starts_with($cible, '//')) {
            $cible = '/';
        }

        // Ticket = "userId.exp.signature" — trois morceaux, pas un de plus.
        $parts = explode('.', $ticket);
        if (count($parts) !== 3) {
            return $this->redirect('/login');
        }
        [$userId, $exp, $signature] = $parts;

        // hash_equals : comparaison en temps constant (anti timing attack).
        $attendu = hash_hmac('sha256', $userId . '.' . $exp, $this->secret);
        if (!hash_equals($attendu, $signature) || (int) $exp < time()) {
            return $this->redirect('/login'); // signature fausse OU expiré
        }

        $user = $this->userRepo->find((int) $userId);
        if ($user === null) {
            return $this->redirect('/login');
        }

        // Connexion programmatique sur le firewall web `pirb` : Symfony pose
        // le cookie de session dans la réponse — la WebView le garde, et
        // toutes les pages web suivantes de l'app sont déjà connectées.
        $this->security->login($user, 'form_login', 'pirb');

        return new RedirectResponse($cible);
    }
}
