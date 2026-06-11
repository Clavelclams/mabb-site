<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Core\ConnexionLog;
use App\Entity\Core\User;
use App\Repository\Core\ConnexionLogRepository;
use App\Repository\Core\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * B2 — Sécu jury : log toute tentative de connexion.
 *
 * Écoute les événements Symfony 7.4 :
 *   - LoginSuccessEvent  (succès) → log + update lastLoginAt
 *   - LoginFailureEvent  (échec)  → log + raison + alerte brute-force
 *
 * En cas d'échec :
 *   - INSERT connexion_log (succes=false, raison_echec=...)
 *   - Si 5 échecs même IP en 10min → log un WARNING (anti-brute-force passif)
 *     (le blocage actif viendra avec un RateLimiter Symfony dédié — V2)
 */
class LoginLogListener
{
    /** Au-delà de N échecs sur fenêtre, on log un WARNING. */
    private const ANTI_BRUTE_FORCE_THRESHOLD = 5;
    private const ANTI_BRUTE_FORCE_WINDOW_MIN = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnexionLogRepository $logRepo,
        private readonly UserRepository $userRepo,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $log = ConnexionLog::succes(
            $user,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            $this->detectContexte($request->getHost()),
        );

        // Met à jour lastLoginAt sur le User
        $user->setLastLoginAt(new \DateTimeImmutable());

        $this->em->persist($log);
        $this->em->flush();
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();

        // Récup l'email tenté depuis le POST
        $emailTente = (string) $request->request->get('_email', '');
        $emailTente = $emailTente !== '' ? strtolower(trim($emailTente)) : null;

        // Si l'email existe en base, on associe — sinon user_id reste null
        $user = $emailTente !== null
            ? $this->userRepo->findOneBy(['email' => $emailTente])
            : null;

        $raison = $this->detectRaisonEchec($event);
        $ip = $request->getClientIp();

        $log = ConnexionLog::echec(
            $emailTente,
            $user,
            $ip,
            $request->headers->get('User-Agent'),
            $raison,
            $this->detectContexte($request->getHost()),
        );

        $this->em->persist($log);
        $this->em->flush();

        // Détection brute-force passive
        if ($ip !== null) {
            $count = $this->logRepo->countFailuresByIp($ip, self::ANTI_BRUTE_FORCE_WINDOW_MIN);
            if ($count >= self::ANTI_BRUTE_FORCE_THRESHOLD) {
                $this->logger->warning('Anti-brute-force : IP suspecte', [
                    'ip'           => $ip,
                    'failures_10m' => $count,
                    'email_tente'  => $emailTente,
                    'contexte'     => $this->detectContexte($request->getHost()),
                ]);
            }
        }
    }

    private function detectContexte(string $host): string
    {
        if (str_starts_with($host, 'pirb.')) {
            return ConnexionLog::CONTEXTE_PIRB;
        }
        if (str_starts_with($host, 'manager.')) {
            return ConnexionLog::CONTEXTE_MANAGER;
        }
        return ConnexionLog::CONTEXTE_ADMIN; // mabb.fr/admin
    }

    private function detectRaisonEchec(LoginFailureEvent $event): string
    {
        $exception = $event->getException();
        $msg = $exception?->getMessageKey() ?? '';

        return match (true) {
            str_contains($msg, 'Bad credentials')      => ConnexionLog::ECHEC_MOTDEPASSE,
            str_contains($msg, 'Username could not')   => ConnexionLog::ECHEC_USER_INTROUVABLE,
            str_contains($msg, 'disabled')             => ConnexionLog::ECHEC_COMPTE_DESACTIVE,
            str_contains($msg, 'Invalid CSRF')         => ConnexionLog::ECHEC_CSRF_INVALIDE,
            default                                    => ConnexionLog::ECHEC_AUTRE,
        };
    }
}
