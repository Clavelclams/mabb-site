<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\SubventionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Subvention demandée à un organisme — Bureau Phase D.4.
 *
 * BUT : tracker tout le cycle de vie d'une demande de subvention pour qu'à
 * l'AG, le président puisse dire "X € demandés, Y € accordés, Z € touchés
 * cette saison". Sans ça, c'est l'inconnu et c'est invendable au CA.
 *
 * WORKFLOW (5 statuts) :
 *
 *   EN_PREPARATION ──► DEPOSEE ──► ACCORDEE ──► TOUCHEE
 *                          │
 *                          └──► REJETEE
 *
 *   EN_PREPARATION : on rédige le dossier (pas encore envoyé)
 *   DEPOSEE        : envoyé à l'organisme, en attente de réponse
 *   ACCORDEE       : accord reçu, en attente du virement
 *   TOUCHEE        : argent reçu sur le compte → crée auto une OperationTresorerie
 *   REJETEE        : refus de l'organisme, avec motif si possible
 *
 * SIMPLIFICATIONS MVP D.4 :
 *   - Versement unique (pas de tranches partielles). Si une subvention est
 *     versée en 2 fois (rare), on saisit 2 lignes.
 *   - Pas de gestion documentaire interne — juste un lien URL externe
 *     (Google Drive typique) où le dossier est stocké.
 *   - Pas de notifications/relances automatiques sur les délais.
 *
 * MULTI-TENANT : ClubAwareInterface — protégée par TresorerieVoter.
 */
#[ORM\Entity(repositoryClass: SubventionRepository::class)]
#[ORM\Table(name: 'subvention')]
#[ORM\HasLifecycleCallbacks]
class Subvention implements ClubAwareInterface
{
    // ====================================================================
    // STATUTS
    // ====================================================================

    public const STATUT_EN_PREPARATION = 'EN_PREPARATION';
    public const STATUT_DEPOSEE        = 'DEPOSEE';
    public const STATUT_ACCORDEE       = 'ACCORDEE';
    public const STATUT_TOUCHEE        = 'TOUCHEE';
    public const STATUT_REJETEE        = 'REJETEE';

    public const STATUTS = [
        self::STATUT_EN_PREPARATION,
        self::STATUT_DEPOSEE,
        self::STATUT_ACCORDEE,
        self::STATUT_TOUCHEE,
        self::STATUT_REJETEE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_EN_PREPARATION => 'En préparation',
        self::STATUT_DEPOSEE        => 'Déposée',
        self::STATUT_ACCORDEE       => 'Accordée',
        self::STATUT_TOUCHEE        => 'Touchée',
        self::STATUT_REJETEE        => 'Rejetée',
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
     * Nom de l'organisme financeur.
     * Ex: "Mairie d'Amiens", "Département de la Somme", "FFBB", "ANS",
     *     "Cité Éducative", "Région Hauts-de-France".
     */
    #[ORM\Column(length: 150)]
    private string $organisme = '';

    /** Intitulé / objet de la subvention (ex: "Achat équipements saison 2025-2026") */
    #[ORM\Column(length: 255)]
    private string $intitule = '';

    /** Référence du dossier côté organisme (optionnel, ex: "MEA-2025-0142") */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referenceDossier = null;

    /**
     * URL externe vers le dossier (Google Drive, Dropbox, etc.).
     * Pas de gestion documentaire interne pour D.4 — on délègue le stockage
     * réel à l'outil que le trésorier maîtrise déjà.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lienDossier = null;

    /** Montant demandé dans le dossier — connu dès EN_PREPARATION/DEPOSEE */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montantDemande = '0.00';

    /** Montant accordé — null tant que pas accordé. Peut être < demandé. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantAccorde = null;

    /** Montant effectivement reçu — null tant que pas touché */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $montantTouche = null;

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_EN_PREPARATION;

    /**
     * Saison concernée (YYYY-YYYY). Utile pour les bilans annuels.
     * Une subvention peut être faite à cheval sur 2 années civiles mais ne
     * concerne qu'UNE saison sportive.
     */
    #[ORM\Column(length: 9)]
    private string $saison = '';

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDepot = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDecision = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateTouche = null;

    /** Motif de rejet — obligatoire si statut REJETEE */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifRejet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Opération créée auto quand la subvention passe en TOUCHEE.
     * Relation OneToOne inversée (côté propriétaire = OperationTresorerie).
     *
     * NOTE : on ne crée PAS encore le mappedBy côté OperationTresorerie pour
     * D.4 — la subvention référence l'opération via son ID dans les notes
     * de l'opération (champ texte). Si on veut une vraie relation un jour,
     * on ajoutera la FK avec une migration séparée.
     *
     * Pourquoi cette simplification ? Pour éviter de surcharger
     * OperationTresorerie avec 3 FK (noteFrais + subvention + futur cotisation)
     * qui sont rarement utilisées ensemble. On les couple via "Notes" lisibles.
     */
    #[ORM\Column(nullable: true)]
    private ?int $operationTresorerieId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->saison    = CotisationJoueur::getSaisonCourante();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // HELPERS workflow
    // ====================================================================

    public function isEnPreparation(): bool { return $this->statut === self::STATUT_EN_PREPARATION; }
    public function isDeposee(): bool       { return $this->statut === self::STATUT_DEPOSEE; }
    public function isAccordee(): bool      { return $this->statut === self::STATUT_ACCORDEE; }
    public function isTouchee(): bool       { return $this->statut === self::STATUT_TOUCHEE; }
    public function isRejetee(): bool       { return $this->statut === self::STATUT_REJETEE; }

    /**
     * Une subvention est "finalisée" si elle est TOUCHEE ou REJETEE :
     * plus de transition possible, statut figé.
     */
    public function isFinalisee(): bool
    {
        return $this->isTouchee() || $this->isRejetee();
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    /**
     * Montant "à attendre" : selon le statut on prend le meilleur signal
     * disponible.
     *   - TOUCHEE  → montantTouche (argent effectivement reçu)
     *   - ACCORDEE → montantAccorde (engagement formel)
     *   - DEPOSEE/EN_PREPARATION → montantDemande (espéré)
     *   - REJETEE  → 0
     */
    public function getMontantAttendu(): string
    {
        return match (true) {
            $this->isTouchee()  => (string) $this->montantTouche,
            $this->isAccordee() => (string) $this->montantAccorde,
            $this->isRejetee()  => '0.00',
            default             => $this->montantDemande,
        };
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): self { $this->club = $club; return $this; }

    public function getOrganisme(): string { return $this->organisme; }
    public function setOrganisme(string $org): self { $this->organisme = $org; return $this; }

    public function getIntitule(): string { return $this->intitule; }
    public function setIntitule(string $i): self { $this->intitule = $i; return $this; }

    public function getReferenceDossier(): ?string { return $this->referenceDossier; }
    public function setReferenceDossier(?string $ref): self { $this->referenceDossier = $ref; return $this; }

    public function getLienDossier(): ?string { return $this->lienDossier; }
    public function setLienDossier(?string $lien): self { $this->lienDossier = $lien; return $this; }

    public function getMontantDemande(): string { return $this->montantDemande; }
    public function setMontantDemande(string $m): self
    {
        if (str_starts_with($m, '-')) {
            throw new \InvalidArgumentException('Le montant demandé doit être positif.');
        }
        $this->montantDemande = $m;
        return $this;
    }

    public function getMontantAccorde(): ?string { return $this->montantAccorde; }
    public function setMontantAccorde(?string $m): self
    {
        if ($m !== null && str_starts_with($m, '-')) {
            throw new \InvalidArgumentException('Le montant accordé doit être positif.');
        }
        $this->montantAccorde = $m;
        return $this;
    }

    public function getMontantTouche(): ?string { return $this->montantTouche; }
    public function setMontantTouche(?string $m): self
    {
        if ($m !== null && str_starts_with($m, '-')) {
            throw new \InvalidArgumentException('Le montant touché doit être positif.');
        }
        $this->montantTouche = $m;
        return $this;
    }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): self
    {
        if (!in_array($statut, self::STATUTS, true)) {
            throw new \InvalidArgumentException(sprintf('Statut invalide : "%s"', $statut));
        }
        $this->statut = $statut;
        return $this;
    }

    public function getSaison(): string { return $this->saison; }
    public function setSaison(string $s): self
    {
        if (!preg_match('/^\d{4}-\d{4}$/', $s)) {
            throw new \InvalidArgumentException(sprintf('Saison invalide : "%s"', $s));
        }
        $this->saison = $s;
        return $this;
    }

    public function getDateDepot(): ?\DateTimeImmutable { return $this->dateDepot; }
    public function setDateDepot(?\DateTimeImmutable $d): self { $this->dateDepot = $d; return $this; }

    public function getDateDecision(): ?\DateTimeImmutable { return $this->dateDecision; }
    public function setDateDecision(?\DateTimeImmutable $d): self { $this->dateDecision = $d; return $this; }

    public function getDateTouche(): ?\DateTimeImmutable { return $this->dateTouche; }
    public function setDateTouche(?\DateTimeImmutable $d): self { $this->dateTouche = $d; return $this; }

    public function getMotifRejet(): ?string { return $this->motifRejet; }
    public function setMotifRejet(?string $m): self { $this->motifRejet = $m; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $n): self { $this->notes = $n; return $this; }

    public function getOperationTresorerieId(): ?int { return $this->operationTresorerieId; }
    public function setOperationTresorerieId(?int $id): self { $this->operationTresorerieId = $id; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): self { $this->createdBy = $u; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
