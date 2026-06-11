<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\RgpdRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * B2 — RGPD : demande de droit à l'oubli ou export de données.
 *
 * Workflow :
 *   1. User clique "Demander la suppression" sur son profil
 *      → INSERT rgpd_request statut=pending
 *   2. Admin reçoit notif (admin@mabb.fr) — voir AdminRgpdController
 *   3. Admin clique "Valider" ou "Refuser"
 *      → statut=validee, traitee_at=now, traitee_par=admin
 *   4. Si validée : exécution de RgpdAnonymizer::anonymizeUser($user)
 *      → statut=effectuee
 *   5. Si refusée (ex: contentieux en cours, comptable) : statut=refusee,
 *      motif_admin renseigné
 *
 * IMPORTANT : On NE SUPPRIME PAS le User (préserve les FK historiques :
 * présences, stats, convocations, votes, badges, etc.). On anonymise les
 * champs perso : nom→Anonyme, email→deleted-X@anonyme.local, photo→null...
 * Cf. RgpdAnonymizer.
 */
#[ORM\Entity(repositoryClass: RgpdRequestRepository::class)]
#[ORM\Table(name: 'rgpd_request')]
class RgpdRequest
{
    public const TYPE_EFFACEMENT = 'effacement';
    public const TYPE_EXPORT     = 'export';

    public const STATUT_PENDING    = 'pending';
    public const STATUT_VALIDEE    = 'validee';
    public const STATUT_EFFECTUEE  = 'effectuee';
    public const STATUT_REFUSEE    = 'refusee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_EFFACEMENT;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_PENDING;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $motifUser = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $motifAdmin = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteeAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traiteePar = null;

    public function __construct(User $user, string $type = self::TYPE_EFFACEMENT)
    {
        $this->user = $user;
        $this->type = $type;
        $this->requestedAt = new \DateTimeImmutable();
    }

    // === Getters/Setters ===

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function getType(): string { return $this->type; }
    public function getStatut(): string { return $this->statut; }
    public function getMotifUser(): ?string { return $this->motifUser; }
    public function getMotifAdmin(): ?string { return $this->motifAdmin; }
    public function getRequestedAt(): ?\DateTimeImmutable { return $this->requestedAt; }
    public function getTraiteeAt(): ?\DateTimeImmutable { return $this->traiteeAt; }
    public function getTraiteePar(): ?User { return $this->traiteePar; }

    public function setMotifUser(?string $motif): self { $this->motifUser = $motif; return $this; }
    public function setMotifAdmin(?string $motif): self { $this->motifAdmin = $motif; return $this; }

    public function valider(User $admin): self
    {
        $this->statut = self::STATUT_VALIDEE;
        $this->traiteePar = $admin;
        $this->traiteeAt = new \DateTimeImmutable();
        return $this;
    }

    public function marquerEffectuee(): self
    {
        $this->statut = self::STATUT_EFFECTUEE;
        return $this;
    }

    public function refuser(User $admin, string $motif): self
    {
        $this->statut = self::STATUT_REFUSEE;
        $this->motifAdmin = $motif;
        $this->traiteePar = $admin;
        $this->traiteeAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPending(): bool { return $this->statut === self::STATUT_PENDING; }
}
