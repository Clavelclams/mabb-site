<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\InscriptionSortieRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * InscriptionSortie — un participant à une SORTIE (cf. doc 23, §4.2).
 *
 * Une ligne = un participant (comme une ligne du Google Sheet remplacé).
 * Porte l'identité (licenciée OU saisie libre), l'autorisation parentale, le
 * suivi du paiement et la présence.
 *
 * ⚠️ Distinct de EvenementParticipation : celle-ci exige un User (membre
 * gamifié). Ici on gère aussi des NON-licenciés (nom/prénom libres), donc une
 * entité séparée. Cf. ADR-0011.
 *
 * Multi-tenant : implémente ClubAwareInterface via l'événement → le ClubVoter
 * protège l'entité sans code spécifique (isolation par club).
 *
 * RGPD : contient des données de MINEURS (nom, date de naissance, responsable
 * légal). Accès STAFF uniquement, jamais exposé côté PIRB/public (cf. doc 23 §8).
 */
#[ORM\Entity(repositoryClass: InscriptionSortieRepository::class)]
#[ORM\Table(name: 'sport_inscription_sortie')]
#[ORM\Index(name: 'idx_inscription_sortie_evenement', columns: ['evenement_id'])]
#[ORM\HasLifecycleCallbacks]
class InscriptionSortie implements ClubAwareInterface
{
    // ===== Autorisation parentale =====
    public const AUTORISATION_NON_REQUISE = 'NON_REQUISE';
    public const AUTORISATION_EN_ATTENTE  = 'EN_ATTENTE';
    public const AUTORISATION_RECUE       = 'RECUE';

    public const AUTORISATION_STATUTS = [
        self::AUTORISATION_NON_REQUISE,
        self::AUTORISATION_EN_ATTENTE,
        self::AUTORISATION_RECUE,
    ];

    // ===== Paiement =====
    public const PAIEMENT_GRATUIT   = 'GRATUIT';
    public const PAIEMENT_A_PAYER   = 'A_PAYER';
    public const PAIEMENT_PAYE      = 'PAYE';
    public const PAIEMENT_EXONERE   = 'EXONERE';
    public const PAIEMENT_REMBOURSE = 'REMBOURSE';

    public const PAIEMENT_STATUTS = [
        self::PAIEMENT_GRATUIT,
        self::PAIEMENT_A_PAYER,
        self::PAIEMENT_PAYE,
        self::PAIEMENT_EXONERE,
        self::PAIEMENT_REMBOURSE,
    ];

    public const MOYEN_ESPECE   = 'ESPECE';
    public const MOYEN_CHEQUE   = 'CHEQUE';
    public const MOYEN_VIREMENT = 'VIREMENT';
    public const MOYEN_AUTRE    = 'AUTRE';

    public const MOYENS_PAIEMENT = [
        self::MOYEN_ESPECE,
        self::MOYEN_CHEQUE,
        self::MOYEN_VIREMENT,
        self::MOYEN_AUTRE,
    ];

    // ===== Présence =====
    public const PRESENCE_INSCRIT = 'INSCRIT';
    public const PRESENCE_PRESENT = 'PRESENT';
    public const PRESENCE_ABSENT  = 'ABSENT';

    public const PRESENCES = [
        self::PRESENCE_INSCRIT,
        self::PRESENCE_PRESENT,
        self::PRESENCE_ABSENT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Evenement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Evenement $evenement = null;

    /** Rempli si la participante est licenciée (fiche joueuse). */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    // ===== Identité (saisie libre si non-licenciée) =====

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $prenom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    /** Nom du responsable légal (obligatoire si mineur). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $responsableLegal = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephoneContact = null;

    // ===== Autorisation parentale =====

    #[ORM\Column(length: 20)]
    private string $autorisationStatut = self::AUTORISATION_NON_REQUISE;

    /** v2 : chemin de la décharge signée (stockée hors public/). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $autorisationFichier = null;

    // ===== Paiement (suivi uniquement, aucun encaissement en ligne) =====

    #[ORM\Column(length: 20)]
    private string $paiementStatut = self::PAIEMENT_GRATUIT;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $montantPaye = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $moyenPaiement = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paiementDate = null;

    // ===== Présence =====

    #[ORM\Column(length: 20)]
    private string $presence = self::PRESENCE_INSCRIT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ============ ClubAwareInterface (isolation multi-tenant) ============

    public function getClub(): ?Club
    {
        return $this->evenement?->getClub();
    }

    // ============ Getters / Setters ============

    public function getId(): ?int { return $this->id; }

    public function getEvenement(): ?Evenement { return $this->evenement; }
    public function setEvenement(?Evenement $evenement): static { $this->evenement = $evenement; return $this; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(?string $nom): static { $this->nom = $nom; return $this; }

    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(?string $prenom): static { $this->prenom = $prenom; return $this; }

    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static { $this->dateNaissance = $dateNaissance; return $this; }

    public function getResponsableLegal(): ?string { return $this->responsableLegal; }
    public function setResponsableLegal(?string $responsableLegal): static { $this->responsableLegal = $responsableLegal; return $this; }

    public function getTelephoneContact(): ?string { return $this->telephoneContact; }
    public function setTelephoneContact(?string $telephoneContact): static { $this->telephoneContact = $telephoneContact; return $this; }

    public function getAutorisationStatut(): string { return $this->autorisationStatut; }
    public function setAutorisationStatut(string $autorisationStatut): static { $this->autorisationStatut = $autorisationStatut; return $this; }

    public function getAutorisationFichier(): ?string { return $this->autorisationFichier; }
    public function setAutorisationFichier(?string $autorisationFichier): static { $this->autorisationFichier = $autorisationFichier; return $this; }

    public function getPaiementStatut(): string { return $this->paiementStatut; }
    public function setPaiementStatut(string $paiementStatut): static { $this->paiementStatut = $paiementStatut; return $this; }

    public function getMontantPaye(): ?string { return $this->montantPaye; }
    public function setMontantPaye(?string $montantPaye): static { $this->montantPaye = $montantPaye; return $this; }

    public function getMoyenPaiement(): ?string { return $this->moyenPaiement; }
    public function setMoyenPaiement(?string $moyenPaiement): static { $this->moyenPaiement = $moyenPaiement; return $this; }

    public function getPaiementDate(): ?\DateTimeImmutable { return $this->paiementDate; }
    public function setPaiementDate(?\DateTimeImmutable $paiementDate): static { $this->paiementDate = $paiementDate; return $this; }

    public function getPresence(): string { return $this->presence; }
    public function setPresence(string $presence): static { $this->presence = $presence; return $this; }

    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): static { $this->commentaire = $commentaire; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    // ============ Helpers métier ============

    /**
     * Nom affiché : celui de la fiche joueuse si licenciée, sinon la saisie
     * libre. Jamais vide en pratique (règle d'intégrité : joueur OU nom+prénom).
     */
    public function getNomAffichage(): string
    {
        if ($this->joueur !== null) {
            return trim(($this->joueur->getPrenom() ?? '') . ' ' . ($this->joueur->getNom() ?? ''));
        }
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }

    /** Mineure au jour de la sortie (ou aujourd'hui si l'événement n'a pas de date). */
    public function isMineur(): bool
    {
        if ($this->dateNaissance === null) {
            return false;
        }
        $reference = $this->evenement?->getDate() ?? new \DateTimeImmutable();
        return $this->dateNaissance->diff($reference)->y < 18;
    }
}
