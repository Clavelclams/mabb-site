<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\DossierLicenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DossierLicence — suivi administratif d'une licence sur UNE saison.
 * [V2.4g 09/07/2026 — chantier Dashboard Secrétaire]
 *
 * Remplace les fichiers Excel « LICENCIÉS <SITE> <SAISON>.xlsx » de la
 * secrétaire (un fichier par site, un onglet par catégorie). Une ligne =
 * une personne licenciée pour une saison : type de licence, n° FFBB,
 * tarif, aides (Mairie / PASS / chèques collège…), état du paiement, et
 * suivi de RELANCE (qui relancer, quand on l'a fait).
 *
 * `joueur` est NULLABLE : les fichiers contiennent aussi dirigeants,
 * coachs externes et sections masculines qui n'ont pas forcément de
 * fiche Joueur. L'identité (nomComplet…) est donc portée par le dossier.
 *
 * Multi-tenant : ClubAwareInterface → protégé par ClubVoter
 * (CLUB_SECRETARIAT : dirigeant + secrétaire).
 */
#[ORM\Entity(repositoryClass: DossierLicenceRepository::class)]
#[ORM\Table(name: 'sport_dossier_licence')]
#[ORM\Index(name: 'idx_dossier_licence_club_saison', columns: ['club_id', 'saison'])]
#[ORM\UniqueConstraint(name: 'uniq_dossier_licence_numero_saison', columns: ['club_id', 'saison', 'numero_licence'])]
#[ORM\HasLifecycleCallbacks]
class DossierLicence implements ClubAwareInterface
{
    // ===== Type de licence (valeurs libres tolérées à l'import) =====
    public const TYPE_CREATION       = 'CREATION';
    public const TYPE_RENOUVELLEMENT = 'RENOUVELLEMENT';
    public const TYPE_MUTATION       = 'MUTATION';

    public const TYPES = [self::TYPE_CREATION, self::TYPE_RENOUVELLEMENT, self::TYPE_MUTATION];

    // ===== Statut de paiement =====
    /**
     * [V2.4m] NON_RENSEIGNE = dossier préparé (ex. « Préparer la saison »)
     * dont la secrétaire n'a PAS encore fixé le tarif/paiement. N'apparaît
     * JAMAIS dans « À relancer » : on ne relance pas quelqu'un dont on ne
     * sait pas encore ce qu'il doit (retour Clavel : Romy, la secrétaire
     * elle-même, sortait « à relancer en priorité »…).
     */
    public const PAIEMENT_NON_RENSEIGNE = 'NON_RENSEIGNE';
    public const PAIEMENT_EN_ATTENTE = 'EN_ATTENTE';
    public const PAIEMENT_PARTIEL    = 'PARTIEL';
    public const PAIEMENT_PAYE       = 'PAYE';
    public const PAIEMENT_EXONERE    = 'EXONERE'; // gratuit (dirigeants, staff)

    public const PAIEMENT_STATUTS = [
        self::PAIEMENT_NON_RENSEIGNE,
        self::PAIEMENT_EN_ATTENTE,
        self::PAIEMENT_PARTIEL,
        self::PAIEMENT_PAYE,
        self::PAIEMENT_EXONERE,
    ];

    public const PAIEMENT_LABELS = [
        self::PAIEMENT_NON_RENSEIGNE => 'À définir',
        self::PAIEMENT_EN_ATTENTE    => 'À payer',
        self::PAIEMENT_PARTIEL       => 'Partiel',
        self::PAIEMENT_PAYE          => 'Payé',
        self::PAIEMENT_EXONERE       => 'Exonéré',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Fiche joueuse si elle existe (nullable : dirigeants, externes…). */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    /** Saison au format '2026-2027' (même convention que SaisonService). */
    #[ORM\Column(length: 9)]
    private ?string $saison = null;

    /** Site d'entraînement : AMIENS NORD / AMIENS SUD / ETOUVIE… (libre). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $site = null;

    /** Catégorie telle que dans l'Excel : « U13F ( 2015 - 2014 ) », « Dirigeants »… */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $categorie = null;

    /** CREATION / RENOUVELLEMENT / MUTATION (normalisé à l'import si possible). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $typeLicence = null;

    #[ORM\Column(name: 'numero_licence', length: 20, nullable: true)]
    private ?string $numeroLicence = null;

    /** « NOM Prénom » — identité telle que gérée par la secrétaire. */
    #[ORM\Column(length: 160)]
    private ?string $nomComplet = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    /** Tarif tel qu'affiché : '110', '95', 'Gratuit'… (string assumé, source Excel). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $tarif = null;

    /**
     * Détail des aides / modes de paiement, colonnes brutes de l'Excel :
     * { "aide_mairie": "...", "pass": "...", "cheques_college": "...",
     *   "cheques": "...", "especes": "..." } — on ne perd RIEN à l'import.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aides = null;

    #[ORM\Column(length: 20)]
    private string $paiementStatut = self::PAIEMENT_EN_ATTENTE;

    // ===== Suivi de relance (le « qui relancer » de la secrétaire) =====

    /** Date de la dernière relance effectuée (null = jamais relancé). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $relanceLe = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $relanceNote = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ============ ClubAwareInterface ============

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    // ============ Getters / Setters ============

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(?string $v): static { $this->saison = $v; return $this; }

    public function getSite(): ?string { return $this->site; }
    public function setSite(?string $v): static { $this->site = $v !== '' ? $v : null; return $this; }

    public function getCategorie(): ?string { return $this->categorie; }
    public function setCategorie(?string $v): static { $this->categorie = $v !== '' ? trim((string) $v) : null; return $this; }

    public function getTypeLicence(): ?string { return $this->typeLicence; }
    public function setTypeLicence(?string $v): static { $this->typeLicence = $v !== '' ? $v : null; return $this; }

    public function getNumeroLicence(): ?string { return $this->numeroLicence; }
    public function setNumeroLicence(?string $v): static
    {
        $v = $v !== null ? strtoupper(trim($v)) : null;
        $this->numeroLicence = $v !== '' ? $v : null;
        return $this;
    }

    public function getNomComplet(): ?string { return $this->nomComplet; }
    public function setNomComplet(?string $v): static { $this->nomComplet = $v !== null ? trim($v) : null; return $this; }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $v): static { $this->dateNaissance = $v; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): static { $this->telephone = $v !== '' ? $v : null; return $this; }

    public function getTarif(): ?string { return $this->tarif; }
    public function setTarif(?string $v): static { $this->tarif = $v !== '' ? $v : null; return $this; }

    public function getAides(): ?array { return $this->aides; }
    public function setAides(?array $v): static { $this->aides = $v !== [] ? $v : null; return $this; }

    public function getPaiementStatut(): string { return $this->paiementStatut; }
    public function setPaiementStatut(string $v): static
    {
        $this->paiementStatut = in_array($v, self::PAIEMENT_STATUTS, true) ? $v : self::PAIEMENT_EN_ATTENTE;
        return $this;
    }

    public function getRelanceLe(): ?\DateTimeImmutable { return $this->relanceLe; }
    public function setRelanceLe(?\DateTimeImmutable $v): static { $this->relanceLe = $v; return $this; }

    public function getRelanceNote(): ?string { return $this->relanceNote; }
    public function setRelanceNote(?string $v): static { $this->relanceNote = $v !== '' ? $v : null; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v !== '' ? $v : null; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // ============ Helpers métier ============

    /** Le dossier doit-il apparaître dans la liste « à relancer » ? */
    public function estArelancer(): bool
    {
        return in_array($this->paiementStatut, [self::PAIEMENT_EN_ATTENTE, self::PAIEMENT_PARTIEL], true);
    }

    public function getPaiementLabel(): string
    {
        return self::PAIEMENT_LABELS[$this->paiementStatut] ?? $this->paiementStatut;
    }
}
