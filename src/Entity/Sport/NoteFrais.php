<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\NoteFraisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Note de frais (demande de remboursement) — Bureau Phase D.2.
 *
 * WORKFLOW :
 *   EN_ATTENTE → VALIDEE  : trésorier valide → crée auto une OperationTresorerie
 *                          de type DEPENSE catégorie REMBOURSEMENTS, liée 1:1.
 *   EN_ATTENTE → REJETEE  : trésorier rejette → motif obligatoire.
 *
 * IMMUTABILITÉ après validation/rejet :
 *   - Une note VALIDEE ou REJETEE est FIGÉE. Aucune modif, aucune suppression.
 *   - Si erreur côté trésorier (validation par erreur) → on crée une opération
 *     corrective dans la trésorerie. C'est le principe de la contre-passation
 *     en compta : on ne supprime pas, on annule par une écriture opposée.
 *   - Empêche aussi les fraudes (impossible de modifier une note après validation).
 *
 * MULTI-TENANT :
 *   - club FK NOT NULL → CASCADE si club supprimé.
 *   - Implémente ClubAwareInterface → protégée par les voters.
 *
 * RGPD :
 *   - demandeur SET NULL si user supprimé → on garde la trace compta sans
 *     l'identité (le montant reste mais "demandeur inconnu").
 *   - validateur idem.
 */
#[ORM\Entity(repositoryClass: NoteFraisRepository::class)]
#[ORM\Table(name: 'note_frais')]
#[ORM\HasLifecycleCallbacks]
class NoteFrais implements ClubAwareInterface
{
    // ====================================================================
    // Statuts du workflow
    // ====================================================================

    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUT_VALIDEE    = 'VALIDEE';
    public const STATUT_REJETEE    = 'REJETEE';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_VALIDEE,
        self::STATUT_REJETEE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_EN_ATTENTE => 'En attente',
        self::STATUT_VALIDEE    => 'Validée',
        self::STATUT_REJETEE    => 'Rejetée',
    ];

    // ====================================================================
    // CHAMPS
    // ====================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Qui a déposé la note (coach, dirigeant, bénévole…).
     * SET NULL si user supprimé pour conserver la trace financière sans l'identité.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $demandeur = null;

    /** Montant en euros, toujours positif (cf. OperationTresorerie pour la raison). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant = '0.00';

    /** Date à laquelle la dépense a été engagée (pas la date de saisie). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDepense;

    #[ORM\Column(length: 255)]
    private string $libelle = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // Justificatif OBLIGATOIRE pour les notes de frais
    // (sinon impossible de valider légalement le remboursement)
    #[ORM\Column(length: 255)]
    private string $justificatifPath = '';

    #[ORM\Column(length: 255)]
    private string $justificatifNomOriginal = '';

    #[ORM\Column(length: 100)]
    private string $justificatifMimeType = '';

    #[ORM\Column]
    private int $justificatifTaille = 0;

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_ATTENTE;

    /** Trésorier (ou SUPER_ADMIN) qui a validé / rejeté. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validateur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValidation = null;

    /** Motif de rejet — obligatoire si statut REJETEE, null sinon. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifRejet = null;

    /**
     * Opération de trésorerie créée AUTOMATIQUEMENT lors de la validation.
     * NULL tant que la note n'est pas validée.
     * Relation 1:1 : une note validée → exactement une opération de remboursement.
     *
     * Propriétaire (mappedBy côté OperationTresorerie) : c'est l'opération qui
     * porte la FK. Permet de naviguer NoteFrais → Operation sans charger d'autres
     * opérations du club.
     */
    #[ORM\OneToOne(mappedBy: 'noteFrais', targetEntity: OperationTresorerie::class)]
    private ?OperationTresorerie $operationTresorerie = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->dateDepense = new \DateTimeImmutable();
        $this->createdAt   = new \DateTimeImmutable();
    }

    // ====================================================================
    // HELPERS workflow
    // ====================================================================

    public function isEnAttente(): bool { return $this->statut === self::STATUT_EN_ATTENTE; }
    public function isValidee(): bool   { return $this->statut === self::STATUT_VALIDEE; }
    public function isRejetee(): bool   { return $this->statut === self::STATUT_REJETEE; }

    /**
     * Une note est verrouillée dès qu'elle n'est plus EN_ATTENTE.
     * Plus aucune modification ni suppression possible côté UI.
     */
    public function isVerrouillee(): bool
    {
        return $this->statut !== self::STATUT_EN_ATTENTE;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    public function getJustificatifTailleHumaine(): string
    {
        $mo = $this->justificatifTaille / 1024 / 1024;
        if ($mo >= 1) {
            return sprintf('%.1f Mo', $mo);
        }
        return sprintf('%d Ko', max(1, (int) ($this->justificatifTaille / 1024)));
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): self { $this->club = $club; return $this; }

    public function getDemandeur(): ?User { return $this->demandeur; }
    public function setDemandeur(?User $demandeur): self { $this->demandeur = $demandeur; return $this; }

    public function getMontant(): string { return $this->montant; }
    public function setMontant(string $montant): self
    {
        if (str_starts_with($montant, '-')) {
            throw new \InvalidArgumentException('Le montant d\'une note de frais doit être positif.');
        }
        $this->montant = $montant;
        return $this;
    }

    public function getDateDepense(): \DateTimeImmutable { return $this->dateDepense; }
    public function setDateDepense(\DateTimeImmutable $date): self { $this->dateDepense = $date; return $this; }

    public function getLibelle(): string { return $this->libelle; }
    public function setLibelle(string $libelle): self { $this->libelle = $libelle; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getJustificatifPath(): string { return $this->justificatifPath; }
    public function setJustificatifPath(string $path): self { $this->justificatifPath = $path; return $this; }

    public function getJustificatifNomOriginal(): string { return $this->justificatifNomOriginal; }
    public function setJustificatifNomOriginal(string $nom): self { $this->justificatifNomOriginal = $nom; return $this; }

    public function getJustificatifMimeType(): string { return $this->justificatifMimeType; }
    public function setJustificatifMimeType(string $mime): self { $this->justificatifMimeType = $mime; return $this; }

    public function getJustificatifTaille(): int { return $this->justificatifTaille; }
    public function setJustificatifTaille(int $taille): self { $this->justificatifTaille = $taille; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): self
    {
        if (!in_array($statut, self::STATUTS, true)) {
            throw new \InvalidArgumentException(sprintf('Statut invalide : "%s"', $statut));
        }
        $this->statut = $statut;
        return $this;
    }

    public function getValidateur(): ?User { return $this->validateur; }
    public function setValidateur(?User $u): self { $this->validateur = $u; return $this; }

    public function getDateValidation(): ?\DateTimeImmutable { return $this->dateValidation; }
    public function setDateValidation(?\DateTimeImmutable $d): self { $this->dateValidation = $d; return $this; }

    public function getMotifRejet(): ?string { return $this->motifRejet; }
    public function setMotifRejet(?string $motif): self { $this->motifRejet = $motif; return $this; }

    public function getOperationTresorerie(): ?OperationTresorerie { return $this->operationTresorerie; }
    public function setOperationTresorerie(?OperationTresorerie $op): self { $this->operationTresorerie = $op; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
