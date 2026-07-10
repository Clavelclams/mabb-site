<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\PreInscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * PreInscription — demande de licence déposée par une FAMILLE [V2.4h].
 *
 * Remplace le Google Form « Formulaire Brut licence » : le parent (ou la
 * joueuse majeure) remplit le formulaire PUBLIC de la vitrine
 * (/pre-inscription). La secrétaire retrouve la demande dans son espace
 * et la CONVERTIT en un clic : dossier licence + contact parent
 * (+ fiche Joueur si elle n'existe pas — détection anti-doublon par
 * nom+prénom normalisés, règle d'or de Clavel : « surtout pas de doublons
 * quand les joueuses de l'an dernier refont leur licence »).
 *
 * RGPD : données personnelles (dont mineures) déposées par la famille —
 * consentement horodaté obligatoire, accès CLUB_SECRETARIAT uniquement,
 * jamais exposé côté public après dépôt. Cf. RGPD-0012.
 */
#[ORM\Entity(repositoryClass: PreInscriptionRepository::class)]
#[ORM\Table(name: 'sport_pre_inscription')]
#[ORM\Index(name: 'idx_pre_inscription_club_statut', columns: ['club_id', 'statut'])]
#[ORM\HasLifecycleCallbacks]
class PreInscription implements ClubAwareInterface
{
    public const STATUT_NOUVELLE  = 'NOUVELLE';
    public const STATUT_CONVERTIE = 'CONVERTIE';
    public const STATUT_REFUSEE   = 'REFUSEE';

    public const STATUTS = [self::STATUT_NOUVELLE, self::STATUT_CONVERTIE, self::STATUT_REFUSEE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Saison visée (auto : saison active au moment du dépôt). */
    #[ORM\Column(length: 9)]
    private ?string $saison = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_NOUVELLE;

    // ===== La joueuse =====

    #[ORM\Column(length: 80)]
    private ?string $nom = null;

    #[ORM\Column(length: 80)]
    private ?string $prenom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    /** Catégorie indiquée par la famille (indicatif — la secrétaire tranche). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $categorie = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephoneJoueuse = null;

    /** Secteur souhaité (nom de Secteur, indicatif). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $secteurSouhaite = null;

    // ===== Le parent / responsable légal =====

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $parentNom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $parentTelephone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $parentEmail = null;

    #[ORM\Column(length: 220, nullable: true)]
    private ?string $parentAdresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $parentCodePostal = null;

    // ===== RGPD + traitement =====

    /** Consentement explicite horodaté (case non pré-cochée, cf. RGPD-0006). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $consentementAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $traitePar = null;

    /** Note de la secrétaire (raison de refus, remarque de conversion…). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $noteTraitement = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getId(): ?int { return $this->id; }

    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(?string $v): static { $this->saison = $v; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $v): static
    {
        $this->statut = in_array($v, self::STATUTS, true) ? $v : self::STATUT_NOUVELLE;
        return $this;
    }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(?string $v): static { $this->nom = $v !== null ? trim($v) : null; return $this; }

    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(?string $v): static { $this->prenom = $v !== null ? trim($v) : null; return $this; }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $v): static { $this->dateNaissance = $v; return $this; }

    public function getCategorie(): ?string { return $this->categorie; }
    public function setCategorie(?string $v): static { $this->categorie = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getTelephoneJoueuse(): ?string { return $this->telephoneJoueuse; }
    public function setTelephoneJoueuse(?string $v): static { $this->telephoneJoueuse = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getSecteurSouhaite(): ?string { return $this->secteurSouhaite; }
    public function setSecteurSouhaite(?string $v): static { $this->secteurSouhaite = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getParentNom(): ?string { return $this->parentNom; }
    public function setParentNom(?string $v): static { $this->parentNom = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getParentTelephone(): ?string { return $this->parentTelephone; }
    public function setParentTelephone(?string $v): static { $this->parentTelephone = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getParentEmail(): ?string { return $this->parentEmail; }
    public function setParentEmail(?string $v): static { $this->parentEmail = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getParentAdresse(): ?string { return $this->parentAdresse; }
    public function setParentAdresse(?string $v): static { $this->parentAdresse = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getParentCodePostal(): ?string { return $this->parentCodePostal; }
    public function setParentCodePostal(?string $v): static { $this->parentCodePostal = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getConsentementAt(): ?\DateTimeImmutable { return $this->consentementAt; }
    public function setConsentementAt(?\DateTimeImmutable $v): static { $this->consentementAt = $v; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function setTraiteLe(?\DateTimeImmutable $v): static { $this->traiteLe = $v; return $this; }

    public function getTraitePar(): ?User { return $this->traitePar; }
    public function setTraitePar(?User $v): static { $this->traitePar = $v; return $this; }

    public function getNoteTraitement(): ?string { return $this->noteTraitement; }
    public function setNoteTraitement(?string $v): static { $this->noteTraitement = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    public function isNouvelle(): bool { return $this->statut === self::STATUT_NOUVELLE; }
}
