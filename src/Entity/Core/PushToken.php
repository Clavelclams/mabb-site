<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\PushTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * PushToken — [Bloc K, 13/07/2026] le jeton de notification d'un appareil.
 *
 * COMMENT LE PUSH MARCHE, EN TROIS PHRASES :
 *   1. L'app demande la permission, puis reçoit d'Expo un jeton unique qui
 *      identifie CET appareil (« ExponentPushToken[xxxxx] »).
 *   2. Elle l'envoie à notre serveur, qui le range ici, rattaché à l'utilisateur.
 *   3. Quand on veut prévenir quelqu'un, on envoie le message à l'API d'Expo avec
 *      ce jeton. Expo le transmet à Apple (APNs) ou Google (FCM), qui l'affichent.
 *
 * UNE PERSONNE = PLUSIEURS APPAREILS. Une joueuse peut avoir un téléphone et une
 * tablette. Donc PAS de contrainte « un jeton par user », mais un index unique
 * sur le JETON lui-même : un même appareil ne doit exister qu'une fois.
 *
 * LE JETON N'EST PAS UN SECRET dangereux (il ne permet que d'envoyer une notif à
 * cet appareil, pas de lire quoi que ce soit), mais c'est une donnée personnelle
 * au sens du RGPD : il est supprimé à la déconnexion et à l'effacement du compte.
 */
#[ORM\Entity(repositoryClass: PushTokenRepository::class)]
#[ORM\Table(name: 'push_token')]
#[ORM\UniqueConstraint(name: 'unique_push_token', columns: ['token'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_push_token_user')]
class PushToken
{
    public const PLATEFORME_IOS     = 'ios';
    public const PLATEFORME_ANDROID = 'android';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Le jeton Expo : « ExponentPushToken[...] ». */
    #[ORM\Column(length: 255)]
    private string $token = '';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $plateforme = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour à chaque réenregistrement : sert à purger les appareils morts. */
    #[ORM\Column]
    private \DateTimeImmutable $vuAt;

    public function __construct(User $user, string $token, ?string $plateforme = null)
    {
        $this->user = $user;
        $this->token = $token;
        $this->plateforme = $plateforme;
        $this->createdAt = new \DateTimeImmutable();
        $this->vuAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getToken(): string { return $this->token; }
    public function getPlateforme(): ?string { return $this->plateforme; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getVuAt(): \DateTimeImmutable { return $this->vuAt; }

    public function toucher(?string $plateforme = null): static
    {
        $this->vuAt = new \DateTimeImmutable();
        if ($plateforme !== null) { $this->plateforme = $plateforme; }
        return $this;
    }
}
