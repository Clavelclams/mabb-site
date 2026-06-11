<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Core\ResetPasswordRequest;
use App\Entity\Core\User;
use App\Repository\Core\ResetPasswordRequestRepository;
use App\Repository\Core\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * B1 — Sécu jury : gestion centralisée des tokens de reset password.
 *
 * Cette classe encapsule TOUTE la logique de sécurité :
 *   - Génération de token cryptographiquement sûr (random_bytes)
 *   - Hash SHA-256 avant stockage (le token clair ne touche jamais la BDD)
 *   - Anti-brute-force par IP (max 5 demandes par 15 min)
 *   - Anti-énumération : on log silencieusement les requêtes pour emails inexistants
 *   - Envoi mail via Symfony Mailer (TemplatedEmail)
 *   - Validation : expiration 1h + token usable une seule fois (consumedAt)
 *
 * Pourquoi pas de bundle externe ?
 * Pour défendre chaque ligne au jury CDA. Le bundle symfonycasts gère
 * la même chose mais on n'aurait pas la main sur les détails. En dev solo
 * apprenant, mieux vaut comprendre que dépendre.
 */
class ResetPasswordTokenManager
{
    /** Nombre max de demandes par IP sur la fenêtre. */
    private const RATE_LIMIT_MAX = 5;

    /** Fenêtre de rate-limit en minutes. */
    private const RATE_LIMIT_WINDOW_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepo,
        private readonly ResetPasswordRequestRepository $rprRepo,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
    ) {}

    /**
     * Génère un token cryptographique sûr, l'enregistre en BDD (hashé)
     * et envoie le mail. Si l'email n'existe pas, on log silencieusement
     * (anti-énumération : le caller ne doit jamais savoir si l'email existe).
     *
     * Retourne true si tout s'est bien passé (mail envoyé OU email inconnu),
     * false uniquement en cas de rate-limit dépassé.
     *
     * @throws \RuntimeException si le mailer échoue (à catcher dans le controller).
     */
    public function requestReset(string $email, Request $request): bool
    {
        $ip = $request->getClientIp() ?? 'unknown';

        // 1. Rate-limit anti-brute-force par IP
        $recent = $this->rprRepo->countRecentByIp($ip, self::RATE_LIMIT_WINDOW_MINUTES);
        if ($recent >= self::RATE_LIMIT_MAX) {
            $this->logger->warning('Rate-limit reset_password atteint', [
                'ip'    => $ip,
                'count' => $recent,
            ]);
            return false;
        }

        // 2. Cherche l'user — si inexistant, on simule un délai et on log
        $user = $this->userRepo->findOneBy(['email' => strtolower(trim($email))]);
        if ($user === null) {
            // Anti-timing-attack léger : simule un travail
            usleep(random_int(100_000, 300_000));
            $this->logger->info('Demande reset password pour email inconnu', [
                'email_tried' => $email,
                'ip'          => $ip,
            ]);
            return true; // On ment au caller pour pas révéler que l'email n'existe pas
        }

        // 3. Compte désactivé → on log mais on simule le succès
        if (!$user->isActive()) {
            $this->logger->warning('Demande reset password pour compte désactivé', [
                'user_id' => $user->getId(),
                'ip'      => $ip,
            ]);
            return true;
        }

        // 4. Supprime toutes les demandes actives existantes pour cet user
        //    (1 seul token valide à la fois, évite le spam de tokens)
        $this->rprRepo->deleteAllForUser($user);
        $this->em->flush();

        // 5. Génère un token cryptographique
        //    32 bytes = 256 bits d'entropie. Plus que suffisant.
        $tokenClair = bin2hex(random_bytes(32));     // 64 chars hex
        $tokenHash  = hash('sha256', $tokenClair);   // 64 chars hex

        // 6. Persiste la demande (avec le HASH, jamais le token clair)
        $rpr = new ResetPasswordRequest($user, $tokenHash, $ip);
        $this->em->persist($rpr);
        $this->em->flush();

        // 7. Envoie le mail avec le token CLAIR (qui ne sera jamais re-stocké)
        $resetUrl = $this->urlGenerator->generate(
            'manager_reset_password_reset',
            ['token' => $tokenClair],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'MABB Manager'))
            ->to($user->getEmail())
            ->subject('🔐 Réinitialisation de votre mot de passe MABB Manager')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context([
                'user'      => $user,
                'reset_url' => $resetUrl,
                'expires_in_minutes' => (int) (ResetPasswordRequest::TTL_SECONDS / 60),
            ]);

        try {
            $this->mailer->send($email);
            $this->logger->info('Mail reset password envoyé', [
                'user_id' => $user->getId(),
                'ip'      => $ip,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi mail reset password', [
                'user_id' => $user->getId(),
                'error'   => $e->getMessage(),
            ]);
            // Re-throw pour que le controller affiche un message générique d'erreur
            throw new \RuntimeException('Échec envoi mail', 0, $e);
        }

        return true;
    }

    /**
     * Récupère la demande valide associée à un token clair.
     * Retourne null si le token est invalide, expiré ou déjà consommé.
     */
    public function findValidRequest(string $tokenClair): ?ResetPasswordRequest
    {
        // Sanity check : si le token clair n'a pas le bon format, on rejette tout de suite
        if (!preg_match('/^[a-f0-9]{64}$/', $tokenClair)) {
            return null;
        }

        $tokenHash = hash('sha256', $tokenClair);
        return $this->rprRepo->findValidByTokenHash($tokenHash);
    }

    /**
     * Consomme une demande (la marque comme utilisée).
     * À appeler APRÈS avoir effectivement changé le password,
     * pour que le token ne puisse plus être réutilisé.
     */
    public function consume(ResetPasswordRequest $rpr): void
    {
        $rpr->consume();
        $this->em->flush();

        $this->logger->info('Token reset password consommé', [
            'rpr_id'  => $rpr->getId(),
            'user_id' => $rpr->getUser()?->getId(),
        ]);
    }

    /**
     * Cleanup périodique (à brancher sur un cron).
     * Retourne le nombre d'entrées supprimées.
     */
    public function purgeExpired(): int
    {
        return $this->rprRepo->purgeExpired();
    }
}
