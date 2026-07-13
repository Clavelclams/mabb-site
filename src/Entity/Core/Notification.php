<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification in-app pour un utilisateur dans le contexte d'un club.
 *
 * Pourquoi cette entité ?
 *   Certaines actions côté Manager (validation/rejet séance shot chart) doivent
 *   être remontées à la joueuse dans son espace PIRB sans dépendre de la config
 *   email (MAILER_DSN peut être null en dev ou non configuré sur OVH).
 *
 * Types supportés (voir constantes TYPE_*) :
 *   SHOT_CHART_VALIDEE  — un coach a validé une séance de tir
 *   SHOT_CHART_REJETEE  — un coach a rejeté une séance (avec motif optionnel)
 *
 * Multi-tenant :
 *   club_id garantit l'isolation. Un user dans plusieurs clubs a des notifs
 *   distinctes par club.
 *
 * Index composite (destinataire_id, club_id, lue) :
 *   Optimisé pour la requête COUNT sur chaque page PIRB (Twig extension).
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['destinataire_id', 'club_id', 'lue'], name: 'idx_notif_user_club_lue')]
class Notification
{
    // ── Types ───────────────────────────────────────────────────────────────
    public const TYPE_SHOT_CHART_VALIDEE = 'SHOT_CHART_VALIDEE';
    public const TYPE_SHOT_CHART_REJETEE = 'SHOT_CHART_REJETEE';
    // [13/07/2026] Le coach convoque une joueuse pour une rencontre. C'est LA
    // notification hebdomadaire : celle qui fait rouvrir l'app le vendredi soir,
    // et celle sur laquelle le push (à venir) se déclenchera.
    public const TYPE_CONVOCATION        = 'CONVOCATION';

    // ── Champs ─────────────────────────────────────────────────────────────
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Utilisateur destinataire */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $destinataire = null;

    /** Club de contexte (isolation multi-tenant) */
    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Code du type d'événement (voir constantes TYPE_*) */
    #[ORM\Column(length: 60)]
    private string $type = '';

    /**
     * Message optionnel — texte libre (ex: motif de rejet du coach).
     * Max ~500 chars recommandé à l'UI mais pas contraint en base.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /**
     * Route Symfony cible (nom de route, sans paramètre).
     * Permet d'ajouter un lien "Voir" dans la notification.
     * Ex : 'pirb_shot_chart'
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lienRoute = null;

    /** La notification a-t-elle été lue ? */
    #[ORM\Column]
    private bool $lue = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // ── Constructeur ───────────────────────────────────────────────────────
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Accesseurs ─────────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }

    public function getDestinataire(): ?User { return $this->destinataire; }
    public function setDestinataire(?User $destinataire): static
    {
        $this->destinataire = $destinataire;
        return $this;
    }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static
    {
        $this->club = $club;
        return $this;
    }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function getLienRoute(): ?string { return $this->lienRoute; }
    public function setLienRoute(?string $lienRoute): static
    {
        $this->lienRoute = $lienRoute;
        return $this;
    }

    public function isLue(): bool { return $this->lue; }
    public function setLue(bool $lue): static
    {
        $this->lue = $lue;
        return $this;
    }
    public function markAsRead(): static
    {
        $this->lue = true;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Libellé lisible pour l'affichage dans le template.
     */
    public function getLibelle(): string
    {
        return match ($this->type) {
            self::TYPE_SHOT_CHART_VALIDEE => '✅ Séance validée',
            self::TYPE_SHOT_CHART_REJETEE => '❌ Séance rejetée',
            self::TYPE_CONVOCATION        => '🏀 Tu es convoquée',
            default => ucfirst(strtolower(str_replace('_', ' ', $this->type))),
        };
    }

    /**
     * Couleur CSS associée au type (pour le badge / icône dans le template).
     */
    public function getCouleur(): string
    {
        return match ($this->type) {
            self::TYPE_SHOT_CHART_VALIDEE => 'success',
            self::TYPE_SHOT_CHART_REJETEE => 'danger',
            self::TYPE_CONVOCATION        => 'neutral',
            default => 'neutral',
        };
    }
}
