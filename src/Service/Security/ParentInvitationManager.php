<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\ParentInvitation;
use App\Repository\Sport\ParentInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * [B30c 12/06/2026] Service de gestion des invitations parent par mail.
 *
 * Sécurité identique à ResetPasswordTokenManager (B1) :
 *  - Token random_bytes(32) → 256 bits d'entropie
 *  - Hash SHA-256 en BDD, jamais le clair
 *  - Expiration 14 jours
 *  - Anti-spam : 3 invits max par email sur 24h
 */
class ParentInvitationManager
{
    private const RATE_LIMIT_MAX_24H = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParentInvitationRepository $invitationRepo,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
    ) {}

    /**
     * Envoie une invitation par mail au parent (pas encore inscrit).
     * Retourne true si envoyée, false si rate-limit dépassé.
     */
    public function inviter(string $emailParent, Joueur $joueur, ?User $demandeur): bool
    {
        $emailParent = strtolower(trim($emailParent));

        // Rate-limit anti-spam
        if ($this->invitationRepo->countRecentByEmail($emailParent) >= self::RATE_LIMIT_MAX_24H) {
            $this->logger->warning('Rate-limit parent invitation dépassé', ['email' => $emailParent]);
            return false;
        }

        // Token cryptographique
        $tokenClair = bin2hex(random_bytes(32));   // 64 chars hex
        $tokenHash  = hash('sha256', $tokenClair); // 64 chars

        $invitation = new ParentInvitation($tokenHash, $emailParent, $joueur, $demandeur);
        $this->em->persist($invitation);
        $this->em->flush();

        // URL d'acceptation
        $url = $this->urlGenerator->generate(
            'parent_invitation_accept',
            ['token' => $tokenClair],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // Envoi mail
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, 'MABB Manager'))
            ->to($emailParent)
            ->subject(sprintf('🏀 Tu es invité à rejoindre MABB en tant que parent de %s', $joueur->getPrenom()))
            ->htmlTemplate('emails/parent_invitation.html.twig')
            ->context([
                'joueur'   => $joueur,
                'url'      => $url,
                'ttl_days' => ParentInvitation::TTL_DAYS,
            ]);

        try {
            $this->mailer->send($email);
            $this->logger->info('Invitation parent envoyée', [
                'invitation_id' => $invitation->getId(),
                'email'          => $emailParent,
                'joueur_id'      => $joueur->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Échec envoi invitation parent', [
                'invitation_id' => $invitation->getId(),
                'error'          => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    public function findValidByToken(string $tokenClair): ?ParentInvitation
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $tokenClair)) {
            return null;
        }
        return $this->invitationRepo->findValidByTokenHash(hash('sha256', $tokenClair));
    }

    public function accepter(ParentInvitation $invitation, User $parent): void
    {
        $invitation->accepter($parent);
        $this->em->flush();
        $this->logger->info('Invitation parent acceptée', [
            'invitation_id' => $invitation->getId(),
            'parent_user_id' => $parent->getId(),
        ]);
    }
}
