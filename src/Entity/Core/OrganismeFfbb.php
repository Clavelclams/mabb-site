<?php

declare(strict_types=1);

namespace App\Entity\Core;

use App\Repository\Core\OrganismeFfbbRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * OrganismeFfbb — référentiel (quasi read-only) des organismes officiels FFBB
 * (clubs + ententes), importé depuis l'export « Rechercher un organisme » du
 * site FFBB (~7100 lignes : N° groupement, Nom, Type).
 *
 * Rôle : décider si un club créé sur la plateforme est « OFFICIEL ». Un club
 * est officiel si son `numeroFfbb` correspond à un `OrganismeFfbb.numero`.
 * Sert aussi à l'anti-doublon (un même numéro FFBB ne peut être revendiqué
 * que par un seul club de la plateforme).
 *
 * ⚠️ Ce n'est PAS un Club de la plateforme (aucun UserClubRole, aucune donnée
 * métier) : c'est une simple table de référence alimentée par import.
 */
#[ORM\Entity(repositoryClass: OrganismeFfbbRepository::class)]
#[ORM\Table(name: 'organisme_ffbb')]
#[ORM\UniqueConstraint(name: 'uniq_organisme_ffbb_numero', columns: ['numero'])]
class OrganismeFfbb
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** N° groupement FFBB normalisé (majuscules), ex. « HDF0080036 ». */
    #[ORM\Column(length: 20)]
    private string $numero;

    #[ORM\Column(length: 180)]
    private string $nom;

    /** Libellé FFBB brut : « Club » | « Entente » (nullable par sécurité). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $type = null;

    public function getId(): ?int { return $this->id; }

    public function getNumero(): string { return $this->numero; }
    public function setNumero(string $numero): static { $this->numero = strtoupper(trim($numero)); return $this; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = trim($nom); return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): static { $this->type = $type !== null ? trim($type) : null; return $this; }
}
