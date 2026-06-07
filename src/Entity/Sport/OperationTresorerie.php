<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\OperationTresorerieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opération de trésorerie d'un club — une ligne du livre de banque.
 *
 * Bureau Manager Phase D.1 — fondations trésorerie.
 *
 * PORTÉE :
 *   - Recette OU dépense (mutuellement exclusives)
 *   - Catégorisée selon un plan comptable simplifié (pas le PCG officiel —
 *     largement suffisant pour un dashboard club)
 *   - Justificatif optionnel (PDF, photo ticket, capture)
 *
 * MULTI-TENANT :
 *   - Implémente ClubAwareInterface → protégée automatiquement par ClubVoter
 *   - Toute requête doit filtrer par club_id (jamais de leak entre clubs)
 *
 * RGPD / SÉCU :
 *   - createdBy gardé pour audit (qui a saisi cette ligne)
 *   - Modifier/supprimer reste possible mais l'historique reposera sur les
 *     entrées BDD elles-mêmes (pas de soft-delete pour l'instant)
 *
 * Pour Phase D.2 (notes de frais validées) : champ noteFrais relation
 * inversée — une opération peut provenir d'une note de frais validée.
 */
#[ORM\Entity(repositoryClass: OperationTresorerieRepository::class)]
#[ORM\Table(name: 'operation_tresorerie')]
#[ORM\HasLifecycleCallbacks]
class OperationTresorerie implements ClubAwareInterface
{
    // ====================================================================
    // CONSTANTES — Types d'opération
    // ====================================================================

    public const TYPE_RECETTE = 'RECETTE';
    public const TYPE_DEPENSE = 'DEPENSE';

    public const TYPES = [
        self::TYPE_RECETTE,
        self::TYPE_DEPENSE,
    ];

    // ====================================================================
    // CONSTANTES — Catégories par type (plan comptable simplifié)
    // ====================================================================
    //
    // Pourquoi ces catégories et pas le PCG associatif officiel ?
    //   - Le PCG officiel a ~30 comptes (606x, 64x, 70x, 74x...) → trop
    //     pour une UI dashboard et inutile pour un dirigeant qui regarde.
    //   - Ces 14 catégories couvrent 95% des flux d'un club sportif amateur.
    //   - Pour la compta officielle, on exporte CSV vers l'outil du trésorier (D.5).
    //
    // Si une opération ne rentre dans aucune catégorie : AUTRES_*.

    // Recettes
    public const CAT_COTISATIONS      = 'COTISATIONS';       // licences joueuses
    public const CAT_SUBVENTIONS      = 'SUBVENTIONS';       // mairie, CD80, FFBB...
    public const CAT_DONS             = 'DONS';              // sponsors locaux, dons particuliers
    public const CAT_BUVETTE          = 'BUVETTE';           // ventes événements
    public const CAT_PRESTATIONS      = 'PRESTATIONS';       // animations facturées
    public const CAT_AUTRES_RECETTES  = 'AUTRES_RECETTES';

    // Dépenses
    public const CAT_EQUIPEMENTS      = 'EQUIPEMENTS';       // ballons, maillots, panneaux
    public const CAT_DEPLACEMENTS     = 'DEPLACEMENTS';      // bus, péages, essence
    public const CAT_LOCATIONS        = 'LOCATIONS';         // gymnase, salles, matériel loué
    public const CAT_COMMUNICATION    = 'COMMUNICATION';     // flyers, web, photos
    public const CAT_FORMATIONS       = 'FORMATIONS';        // coachs, arbitres
    public const CAT_REMBOURSEMENTS   = 'REMBOURSEMENTS';    // notes de frais validées (D.2)
    public const CAT_SALAIRES         = 'SALAIRES';          // salariés, indemnités SC
    public const CAT_AUTRES_DEPENSES  = 'AUTRES_DEPENSES';

    /**
     * Liste plate utilisée par les formulaires + validation.
     * L'ordre est volontairement RECETTES d'abord puis DEPENSES, comme dans
     * un compte de résultat associatif simplifié.
     */
    public const CATEGORIES = [
        // Recettes
        self::CAT_COTISATIONS,
        self::CAT_SUBVENTIONS,
        self::CAT_DONS,
        self::CAT_BUVETTE,
        self::CAT_PRESTATIONS,
        self::CAT_AUTRES_RECETTES,
        // Dépenses
        self::CAT_EQUIPEMENTS,
        self::CAT_DEPLACEMENTS,
        self::CAT_LOCATIONS,
        self::CAT_COMMUNICATION,
        self::CAT_FORMATIONS,
        self::CAT_REMBOURSEMENTS,
        self::CAT_SALAIRES,
        self::CAT_AUTRES_DEPENSES,
    ];

    /**
     * Mapping inverse catégorie → type, pour valider qu'une RECETTE n'a pas
     * une catégorie de DEPENSE et vice-versa.
     */
    public const CATEGORIES_PAR_TYPE = [
        self::TYPE_RECETTE => [
            self::CAT_COTISATIONS,
            self::CAT_SUBVENTIONS,
            self::CAT_DONS,
            self::CAT_BUVETTE,
            self::CAT_PRESTATIONS,
            self::CAT_AUTRES_RECETTES,
        ],
        self::TYPE_DEPENSE => [
            self::CAT_EQUIPEMENTS,
            self::CAT_DEPLACEMENTS,
            self::CAT_LOCATIONS,
            self::CAT_COMMUNICATION,
            self::CAT_FORMATIONS,
            self::CAT_REMBOURSEMENTS,
            self::CAT_SALAIRES,
            self::CAT_AUTRES_DEPENSES,
        ],
    ];

    /**
     * Libellés humains affichés en UI (français).
     * Centralisé ici pour pas le dupliquer dans 5 templates.
     */
    public const CATEGORIES_LABELS = [
        self::CAT_COTISATIONS     => 'Cotisations licences',
        self::CAT_SUBVENTIONS     => 'Subventions',
        self::CAT_DONS            => 'Dons / sponsors',
        self::CAT_BUVETTE         => 'Buvette / ventes',
        self::CAT_PRESTATIONS     => 'Prestations',
        self::CAT_AUTRES_RECETTES => 'Autres recettes',
        self::CAT_EQUIPEMENTS     => 'Équipements sportifs',
        self::CAT_DEPLACEMENTS    => 'Déplacements',
        self::CAT_LOCATIONS       => 'Locations (gymnases, matériel)',
        self::CAT_COMMUNICATION   => 'Communication',
        self::CAT_FORMATIONS      => 'Formations',
        self::CAT_REMBOURSEMENTS  => 'Remboursements notes de frais',
        self::CAT_SALAIRES        => 'Salaires & indemnités',
        self::CAT_AUTRES_DEPENSES => 'Autres dépenses',
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

    #[ORM\Column(length: 16)]
    private string $type = self::TYPE_DEPENSE;

    #[ORM\Column(length: 32)]
    private string $categorie = self::CAT_AUTRES_DEPENSES;

    /**
     * Montant en euros, toujours POSITIF.
     * Le signe (+/-) est déduit du type (RECETTE = +, DEPENSE = -).
     *
     * Pourquoi Decimal et pas float ?
     *   - Float = imprécision binaire (0.1 + 0.2 ≠ 0.3 en PHP). Inacceptable en compta.
     *   - Decimal Doctrine = string PHP côté code → calcul avec bcmath au besoin,
     *     stockage exact en BDD (DECIMAL(10,2) = jusqu'à 99 999 999,99 €).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant = '0.00';

    /**
     * Date EFFECTIVE de l'opération (date de l'achat, de l'encaissement),
     * pas la date de saisie. C'est cette date qui compte pour le bilan annuel.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    private string $libelle = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // Justificatif (optionnel) — pattern aligné sur RencontrePdfUploader
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatifPath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatifNomOriginal = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $justificatifMimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $justificatifTaille = null;

    /**
     * Qui a saisi cette opération — utile pour audit.
     * Si le User est supprimé (RGPD), on garde l'opération mais le champ devient null.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Note de frais d'origine (si l'opération a été créée automatiquement par
     * validation d'une note de frais — Phase D.2). NULL pour les opérations
     * saisies manuellement par le trésorier.
     *
     * Relation 1:1 : une note validée → exactement une opération de remboursement.
     * Côté PROPRIÉTAIRE (la FK est ici), pour pouvoir charger une opération
     * sans hydrater la note. SET NULL si la note est supprimée (cas edge,
     * notes EN_ATTENTE peuvent être supprimées par leur demandeur).
     */
    #[ORM\OneToOne(inversedBy: 'operationTresorerie', targetEntity: NoteFrais::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?NoteFrais $noteFrais = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->date      = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // HELPERS métier
    // ====================================================================

    /**
     * Montant signé : positif pour recette, négatif pour dépense.
     * Pratique pour calculer un solde par sum() côté repo.
     */
    public function getMontantSigne(): string
    {
        return $this->type === self::TYPE_RECETTE
            ? $this->montant
            : '-' . $this->montant;
    }

    /**
     * Libellé humain de la catégorie pour affichage.
     */
    public function getCategorieLabel(): string
    {
        return self::CATEGORIES_LABELS[$this->categorie] ?? $this->categorie;
    }

    /**
     * Vérifie que la catégorie correspond bien au type (RECETTE/DEPENSE).
     * Appelé en validation (controller ou form) avant persist.
     */
    public function isCategorieValidePourType(): bool
    {
        return in_array(
            $this->categorie,
            self::CATEGORIES_PAR_TYPE[$this->type] ?? [],
            true
        );
    }

    public function hasJustificatif(): bool
    {
        return $this->justificatifPath !== null;
    }

    /**
     * Taille humanisée du justificatif (ex: "1.2 Mo").
     * Retourne chaîne vide si pas de justificatif.
     */
    public function getJustificatifTailleHumaine(): string
    {
        if ($this->justificatifTaille === null) {
            return '';
        }
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

    public function getType(): string { return $this->type; }
    public function setType(string $type): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Type d\'opération invalide : "%s". Attendu : %s.', $type, implode(', ', self::TYPES)));
        }
        $this->type = $type;
        return $this;
    }

    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $categorie): self
    {
        if (!in_array($categorie, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf('Catégorie invalide : "%s".', $categorie));
        }
        $this->categorie = $categorie;
        return $this;
    }

    public function getMontant(): string { return $this->montant; }
    public function setMontant(string $montant): self
    {
        // Refuser un montant négatif côté code : on encode le signe via le type.
        // Si quelqu'un passe "-50.00" c'est une erreur logique → exception explicite.
        if (str_starts_with($montant, '-')) {
            throw new \InvalidArgumentException('Le montant doit être positif. Utilise setType(TYPE_DEPENSE) pour une dépense.');
        }
        $this->montant = $montant;
        return $this;
    }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): self { $this->date = $date; return $this; }

    public function getLibelle(): string { return $this->libelle; }
    public function setLibelle(string $libelle): self { $this->libelle = $libelle; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getJustificatifPath(): ?string { return $this->justificatifPath; }
    public function setJustificatifPath(?string $path): self { $this->justificatifPath = $path; return $this; }

    public function getJustificatifNomOriginal(): ?string { return $this->justificatifNomOriginal; }
    public function setJustificatifNomOriginal(?string $nom): self { $this->justificatifNomOriginal = $nom; return $this; }

    public function getJustificatifMimeType(): ?string { return $this->justificatifMimeType; }
    public function setJustificatifMimeType(?string $mime): self { $this->justificatifMimeType = $mime; return $this; }

    public function getJustificatifTaille(): ?int { return $this->justificatifTaille; }
    public function setJustificatifTaille(?int $taille): self { $this->justificatifTaille = $taille; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $user): self { $this->createdBy = $user; return $this; }

    public function getNoteFrais(): ?NoteFrais { return $this->noteFrais; }
    public function setNoteFrais(?NoteFrais $note): self { $this->noteFrais = $note; return $this; }

    /**
     * Indique si l'opération a été générée automatiquement par validation
     * d'une note de frais. Utile pour bloquer son édition manuelle dans l'UI.
     */
    public function provientDuneNoteFrais(): bool
    {
        return $this->noteFrais !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
