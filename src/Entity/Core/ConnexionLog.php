<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\ConnexionLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * B2 — Sécu jury : trace une tentative de connexion (succès ou échec).
 *
 * RGPD : les logs sont conservés 12 mois max (cf. CNIL recommandation
 * "fichier de logs de connexion"). Au-delà, ils doivent être purgés ou
 * pseudonymisés. À implémenter via cron quotidien (cf. command à venir).
 *
 * Anti-brute-force : on peut compter les échecs par IP sur fenêtre courte
 * pour bloquer une IP suspecte (cf. ConnexionLogRepository::countFailuresByIp).
 *
 * user_id nullable : un échec sur un email inexistant n'a pas de user_id.
 * On stocke quand même l'email tenté (utile pour détecter du credential stuffing).
 */
#[ORM\Entity(repositoryClass: ConnexionLogRepository::class)]
#[ORM\Table(name: 'connexion_log')]
#[ORM\Index(columns: ['ip'], name: 'IDX_CL_IP')]
#[ORM\Index(columns: ['created_at'], name: 'IDX_CL_CREATED')]
#[ORM\Index(columns: ['succes'], name: 'IDX_CL_SUCCES')]
class ConnexionLog
{
    public const CONTEXTE_MANAGER = 'manager';
    public const CONTEXTE_PIRB    = 'pirb';
    public const CONTEXTE_ADMIN   = 'admin';

    public const ECHEC_MOTDEPASSE     = 'bad_credentials';
    public const ECHEC_USER_INTROUVABLE = 'user_not_found';
    public const ECHEC_COMPTE_DESACTIVE = 'account_disabled';
    public const ECHEC_CSRF_INVALIDE    = 'csrf_invalid';
    public const ECHEC_AUTRE            = 'other';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $emailTente = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    /** Tronqué à 500 chars : certains UA peuvent être très longs. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private bool $succes = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $raisonEchec = null;

    /** Contexte : manager / pirb / admin (pour filtrer dans le back-office) */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $contexte = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // === Factory methods : succes / echec ===

    public static function succes(User $user, ?string $ip, ?string $ua, string $contexte): self
    {
        $log = new self();
        $log->user        = $user;
        $log->emailTente  = $user->getEmail();
        $log->ip          = $ip;
        $log->userAgent   = self::truncateUa($ua);
        $log->succes      = true;
        $log->contexte    = $contexte;
        return $log;
    }

    public static function echec(
        ?string $emailTente,
        ?User $user,
        ?string $ip,
        ?string $ua,
        string $raison,
        string $contexte,
    ): self {
        $log = new self();
        $log->user        = $user;
        $log->emailTente  = $emailTente;
        $log->ip          = $ip;
        $log->userAgent   = self::truncateUa($ua);
        $log->succes      = false;
        $log->raisonEchec = $raison;
        $log->contexte    = $contexte;
        return $log;
    }

    private static function truncateUa(?string $ua): ?string
    {
        if ($ua === null) {
            return null;
        }
        return mb_substr($ua, 0, 500);
    }

    // === Getters ===

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getEmailTente(): ?string { return $this->emailTente; }
    public function getIp(): ?string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function isSucces(): bool { return $this->succes; }
    public function getRaisonEchec(): ?string { return $this->raisonEchec; }
    public function getContexte(): ?string { return $this->contexte; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
