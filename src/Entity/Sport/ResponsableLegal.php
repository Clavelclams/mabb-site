<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\ResponsableLegalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ResponsableLegal — contact parent / responsable légal d'une joueuse.
 * [V2.4g 09/07/2026 — chantier Dashboard Secrétaire]
 *
 * POURQUOI une entité dédiée (décision Clavel 09/07/2026) :
 *   - Les coordonnées parents vivaient dans un Google Form → Excel
 *     (« Organisation match », onglet Formulaire Brut licence) : perte de
 *     données à chaque saison, aucune réutilisation.
 *   - Rattachées à la fiche Joueur, elles servent PARTOUT : secrétariat
 *     (relances licences), sorties (autorisations parentales), convocations.
 *   - PAS de compte User parent pour l'instant (YAGNI : aucun usage connecté
 *     identifié ; le rôle PARENT existe déjà si besoin plus tard).
 *
 * ≠ ParentJoueur : ParentJoueur lie un COMPTE User parent à un enfant pour
 * l'accès PIRB « mes enfants » (workflow pending/active). ResponsableLegal
 * est le CARNET D'ADRESSES administratif (contacts sans compte, importés du
 * formulaire licence). Complémentaires — si un parent crée un compte, les
 * deux coexistent ; une fusion éventuelle se fera par rapprochement d'email.
 *
 * RGPD : données personnelles de tiers (parents) liées à des MINEURES.
 * Accès CLUB_SECRETARIAT uniquement (dirigeant + secrétaire), jamais côté
 * PIRB/public. Purge avec le cycle de vie de la fiche joueuse (RGPD-0008).
 *
 * Multi-tenant : ClubAwareInterface via la joueuse → ClubVoter protège
 * l'entité sans code spécifique.
 */
#[ORM\Entity(repositoryClass: ResponsableLegalRepository::class)]
#[ORM\Table(name: 'sport_responsable_legal')]
#[ORM\Index(name: 'idx_responsable_joueur', columns: ['joueur_id'])]
#[ORM\HasLifecycleCallbacks]
class ResponsableLegal implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Ex : « Malonga Nadine (maman) » — tel que saisi par la famille. */
    #[ORM\Column(length: 160)]
    private ?string $nomComplet = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone = null;

    /** Second numéro éventuel (papa/maman, fixe/portable). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 220, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $codePostal = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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
        return $this->joueur?->getClub();
    }

    // ============ Getters / Setters ============

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getNomComplet(): ?string { return $this->nomComplet; }
    public function setNomComplet(?string $v): static { $this->nomComplet = $v !== null ? trim($v) : null; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $v): static { $this->telephone = $v !== '' ? $v : null; return $this; }

    public function getTelephone2(): ?string { return $this->telephone2; }
    public function setTelephone2(?string $v): static { $this->telephone2 = $v !== '' ? $v : null; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v !== '' ? $v : null; return $this; }

    public function getAdresse(): ?string { return $this->adresse; }
    public function setAdresse(?string $v): static { $this->adresse = $v !== '' ? $v : null; return $this; }

    public function getCodePostal(): ?string { return $this->codePostal; }
    public function setCodePostal(?string $v): static { $this->codePostal = $v !== '' ? $v : null; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): static { $this->notes = $v !== '' ? $v : null; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
