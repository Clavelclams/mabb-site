<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\SecteurRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Secteur — un site d'entraînement du club [V2.4h 09/07/2026].
 *
 * Le club fonctionne PAR SECTEUR (Amiens Nord, Amiens Sud, Etouvie…) :
 * un fichier Excel par secteur chez la secrétaire, et un RESPONSABLE DE
 * SECTEUR (« l'équivalent coach en gros, il chapote les coachs et peut
 * être coach lui-même » — Clavel, 09/07/2026, colonne « Ton responsable »
 * du formulaire licence : Willy DUFOSSE, Romy DUFOSSE…).
 *
 * Sert de référentiel : onglets du classeur secrétariat, dropdown de
 * placement des joueuses, choix du secteur dans la pré-inscription
 * publique. `DossierLicence.site` reste une STRING (= Secteur.nom) pour
 * ne pas migrer l'existant — le rapprochement se fait par nom.
 */
#[ORM\Entity(repositoryClass: SecteurRepository::class)]
#[ORM\Table(name: 'sport_secteur')]
#[ORM\UniqueConstraint(name: 'uniq_secteur_club_nom', columns: ['club_id', 'nom'])]
class Secteur implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Ex : « AMIENS NORD », « ETOUVIE » — c'est la valeur de DossierLicence.site. */
    #[ORM\Column(length: 60)]
    private ?string $nom = null;

    /** Responsable de secteur (chapote les coachs du site). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $responsableNom = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $responsableTelephone = null;

    /** Ordre d'affichage des onglets du classeur. */
    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getId(): ?int { return $this->id; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(?string $v): static { $this->nom = $v !== null ? mb_strtoupper(trim($v)) : null; return $this; }

    public function getResponsableNom(): ?string { return $this->responsableNom; }
    public function setResponsableNom(?string $v): static { $this->responsableNom = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getResponsableTelephone(): ?string { return $this->responsableTelephone; }
    public function setResponsableTelephone(?string $v): static { $this->responsableTelephone = ($v !== null && trim($v) !== '') ? trim($v) : null; return $this; }

    public function getOrdre(): int { return $this->ordre; }
    public function setOrdre(int $v): static { $this->ordre = $v; return $this; }
}
