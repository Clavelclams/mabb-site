<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\CotisationJoueurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cotisation d'une joueuse pour UNE saison — Bureau Phase D.3.
 *
 * STRUCTURE :
 *   - 1 joueuse + 1 saison = 1 cotisation maximum (UNIQUE BDD).
 *   - Le statut suit le cycle de vie du paiement.
 *   - Le club est récupéré par DÉLÉGATION via le joueur (pas de FK club directe)
 *     — cohérent avec le pattern ClubAwareInterface (cf. Presence).
 *
 * SIMPLIFICATIONS MVP D.3 :
 *   - Pas de tracking individuel des versements d'un échéancier — juste un
 *     montant total versé et un statut. Si besoin d'historique fin, on
 *     ajoutera l'entité VersementCotisation en D.3.1.
 *   - 4 statuts seulement (pas de "PARTIEL" séparé : on utilise ECHEANCIER
 *     pour tout paiement non finalisé).
 *
 * WORKFLOW :
 *   A_PAYER → PAYEE       (paiement complet → crée auto OperationTresorerie RECETTE)
 *   A_PAYER → ECHEANCIER  (premier versement → MAJ montantPaye)
 *   ECHEANCIER → PAYEE    (versement final → crée auto OperationTresorerie pour solde)
 *   A_PAYER → EXEMPTEE    (motif obligatoire — pas de paiement attendu)
 *
 * NOTE : la création de l'OperationTresorerie est faite par CotisationPayeur
 * (service métier) — pas par cette entité (SRP).
 */
#[ORM\Entity(repositoryClass: CotisationJoueurRepository::class)]
#[ORM\Table(name: 'cotisation_joueur')]
// Contrainte UNIQUE (joueur, saison) : impossible de créer 2 cotisations pour
// la même joueuse sur la même saison. Garantit la cohérence côté BDD.
#[ORM\UniqueConstraint(name: 'UNIQ_COTI_JOUEUR_SAISON', columns: ['joueur_id', 'saison'])]
#[ORM\HasLifecycleCallbacks]
class CotisationJoueur implements ClubAwareInterface
{
    // ====================================================================
    // STATUTS
    // ====================================================================

    public const STATUT_A_PAYER    = 'A_PAYER';
    public const STATUT_PAYEE      = 'PAYEE';
    public const STATUT_ECHEANCIER = 'ECHEANCIER';
    public const STATUT_EXEMPTEE   = 'EXEMPTEE';

    public const STATUTS = [
        self::STATUT_A_PAYER,
        self::STATUT_PAYEE,
        self::STATUT_ECHEANCIER,
        self::STATUT_EXEMPTEE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_A_PAYER    => 'À payer',
        self::STATUT_PAYEE      => 'Payée',
        self::STATUT_ECHEANCIER => 'Échéancier en cours',
        self::STATUT_EXEMPTEE   => 'Exemptée',
    ];

    /**
     * Saison courante calculée selon la date du jour.
     * Convention sportive française : saison N/N+1 court du 1er septembre N
     * au 31 août N+1.
     *   - On est en sept 2025 → août 2026 → saison "2025-2026"
     *   - On est en avril 2026 → saison "2025-2026" (toujours en cours)
     *   - On est en septembre 2026 → saison "2026-2027"
     */
    public static function getSaisonCourante(?\DateTimeInterface $reference = null): string
    {
        $ref = $reference ?? new \DateTimeImmutable();
        $annee = (int) $ref->format('Y');
        $mois  = (int) $ref->format('n');

        // Avant septembre : on est encore sur la saison qui a démarré l'an passé.
        // Septembre et après : nouvelle saison.
        if ($mois < 9) {
            return sprintf('%d-%d', $annee - 1, $annee);
        }
        return sprintf('%d-%d', $annee, $annee + 1);
    }

    /**
     * Liste des saisons récentes utilisable dans un dropdown UI.
     * Couvre la saison courante + les 4 précédentes.
     *
     * @return string[]
     */
    public static function getSaisonsRecentes(?\DateTimeInterface $reference = null): array
    {
        $courante = self::getSaisonCourante($reference);
        // Extraire l'année de début de la saison courante (ex: 2025 dans "2025-2026")
        $anneeDebut = (int) substr($courante, 0, 4);

        $saisons = [];
        for ($i = 0; $i < 5; $i++) {
            $debut = $anneeDebut - $i;
            $saisons[] = sprintf('%d-%d', $debut, $debut + 1);
        }
        return $saisons;
    }

    // ====================================================================
    // CHAMPS
    // ====================================================================

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Format "YYYY-YYYY" (ex: "2025-2026"). */
    #[ORM\Column(length: 9)]
    private string $saison = '';

    /** Montant attendu (peut différer du tarif officiel : réductions famille, etc.) */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montantAttendu = '0.00';

    /**
     * Montant déjà versé (utile pour l'échéancier).
     * Vaut montantAttendu quand statut = PAYEE.
     * Vaut 0.00 quand statut = A_PAYER ou EXEMPTEE.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montantPaye = '0.00';

    #[ORM\Column(length: 16)]
    private string $statut = self::STATUT_A_PAYER;

    /** Date du paiement final (quand statut = PAYEE). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePaiement = null;

    /** Obligatoire si EXEMPTEE — ex: "Service civique en mission au club" */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifExemption = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->saison = self::getSaisonCourante();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Délégation au Joueur pour ClubAwareInterface — multi-tenant strict.
     * Le ClubVoter et TresorerieVoter récupèrent le club via cette méthode.
     */
    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }

    public function isAPayer(): bool      { return $this->statut === self::STATUT_A_PAYER; }
    public function isPayee(): bool       { return $this->statut === self::STATUT_PAYEE; }
    public function isEcheancier(): bool  { return $this->statut === self::STATUT_ECHEANCIER; }
    public function isExemptee(): bool    { return $this->statut === self::STATUT_EXEMPTEE; }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    /**
     * Montant restant dû. Pour un échéancier ou un statut A_PAYER.
     * Retourne "0.00" pour PAYEE et EXEMPTEE.
     */
    public function getMontantRestant(): string
    {
        if ($this->statut === self::STATUT_PAYEE || $this->statut === self::STATUT_EXEMPTEE) {
            return '0.00';
        }
        $restant = bcsub($this->montantAttendu, $this->montantPaye, 2);
        // Sécurité : jamais négatif
        if (bccomp($restant, '0', 2) < 0) {
            return '0.00';
        }
        return $restant;
    }

    /**
     * Pourcentage payé (0-100) — pour barre de progression UI.
     */
    public function getPourcentagePaye(): int
    {
        if ($this->isExemptee() || bccomp($this->montantAttendu, '0', 2) <= 0) {
            return 0;
        }
        if ($this->isPayee()) {
            return 100;
        }
        $ratio = (float) $this->montantPaye / (float) $this->montantAttendu;
        return (int) round($ratio * 100);
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): self { $this->joueur = $joueur; return $this; }

    public function getSaison(): string { return $this->saison; }
    public function setSaison(string $saison): self
    {
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            throw new \InvalidArgumentException(sprintf('Saison invalide : "%s". Format attendu : YYYY-YYYY.', $saison));
        }
        $this->saison = $saison;
        return $this;
    }

    public function getMontantAttendu(): string { return $this->montantAttendu; }
    public function setMontantAttendu(string $montant): self
    {
        if (str_starts_with($montant, '-')) {
            throw new \InvalidArgumentException('Le montant attendu doit être positif ou nul.');
        }
        $this->montantAttendu = $montant;
        return $this;
    }

    public function getMontantPaye(): string { return $this->montantPaye; }
    public function setMontantPaye(string $montant): self
    {
        if (str_starts_with($montant, '-')) {
            throw new \InvalidArgumentException('Le montant payé doit être positif ou nul.');
        }
        $this->montantPaye = $montant;
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

    public function getDatePaiement(): ?\DateTimeImmutable { return $this->datePaiement; }
    public function setDatePaiement(?\DateTimeImmutable $d): self { $this->datePaiement = $d; return $this; }

    public function getMotifExemption(): ?string { return $this->motifExemption; }
    public function setMotifExemption(?string $m): self { $this->motifExemption = $m; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
