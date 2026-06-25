<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Repository\Sport\ThemeSeanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ThemeSeance — thématique pédagogique d'une séance de basket.
 *
 * Deux niveaux :
 *   - isSysteme = true  → thème pré-défini (migration), non supprimable, visible par tous les clubs
 *   - isSysteme = false → thème custom créé par l'admin d'un club (club_id non null)
 *
 * Les thèmes sont groupés (Attaque / Défense / Collectif / Physique) pour
 * faciliter l'affichage dans le formulaire multi-sélection.
 *
 * Usage : ContenuSeance.themes (ManyToMany)
 */
#[ORM\Entity(repositoryClass: ThemeSeanceRepository::class)]
#[ORM\Table(name: 'theme_seance')]
#[ORM\UniqueConstraint(name: 'UNQ_TS_SLUG', columns: ['slug'])]
class ThemeSeance
{
    public const GROUPE_ATTAQUE   = 'Attaque';
    public const GROUPE_DEFENSE   = 'Défense';
    public const GROUPE_COLLECTIF = 'Collectif';
    public const GROUPE_PHYSIQUE  = 'Physique / Technique';

    public const GROUPES = [
        self::GROUPE_ATTAQUE,
        self::GROUPE_DEFENSE,
        self::GROUPE_COLLECTIF,
        self::GROUPE_PHYSIQUE,
    ];

    /**
     * Thèmes système pré-définis.
     * Clé = slug unique, Valeur = [libelle, groupe]
     */
    public const THEMES_SYSTEME = [
        // Attaque
        'jeu-passe'            => ['Jeu en passe',            self::GROUPE_ATTAQUE],
        'dribble-penetration'  => ['Dribble / pénétration',   self::GROUPE_ATTAQUE],
        '1c1-offensif'         => ['1c1 offensif',             self::GROUPE_ATTAQUE],
        'tir-mi-distance'      => ['Tir mi-distance',          self::GROUPE_ATTAQUE],
        'tir-3pts'             => ['Tir à 3 points',           self::GROUPE_ATTAQUE],
        'layup-finition'       => ['Lay-up / Finition',        self::GROUPE_ATTAQUE],
        'pick-and-roll'        => ['Pick & Roll',               self::GROUPE_ATTAQUE],
        'transition-offensive' => ['Transition offensive',     self::GROUPE_ATTAQUE],
        'isolation'            => ['Jeu en isolation',         self::GROUPE_ATTAQUE],
        // Défense
        'defense-porteur'      => ['Défense porteur',          self::GROUPE_DEFENSE],
        'defense-non-porteur'  => ['Défense non-porteur',      self::GROUPE_DEFENSE],
        'aide-defensive'       => ['Aide défensive',           self::GROUPE_DEFENSE],
        'defense-zone'         => ['Défense en zone',          self::GROUPE_DEFENSE],
        'press-defensif'       => ['Press défensif',           self::GROUPE_DEFENSE],
        '1c1-defensif'         => ['1c1 défensif',             self::GROUPE_DEFENSE],
        'transition-defensive' => ['Transition défensive',     self::GROUPE_DEFENSE],
        // Collectif
        'systeme-offensif'     => ['Système offensif',         self::GROUPE_COLLECTIF],
        'sortie-zone'          => ['Sortie de zone',           self::GROUPE_COLLECTIF],
        'rebond-offensif'      => ['Rebond offensif',          self::GROUPE_COLLECTIF],
        'rebond-defensif'      => ['Rebond défensif',          self::GROUPE_COLLECTIF],
        'contre-attaque'       => ['Contre-attaque',           self::GROUPE_COLLECTIF],
        'fin-de-match'         => ['Fin de match',             self::GROUPE_COLLECTIF],
        // Physique
        'coordination'         => ['Coordination',             self::GROUPE_PHYSIQUE],
        'cardio'               => ['Cardio / Conditioning',    self::GROUPE_PHYSIQUE],
        'vitesse-explosivite'  => ['Vitesse / Explosivité',    self::GROUPE_PHYSIQUE],
        'proprioception'       => ['Proprioception',           self::GROUPE_PHYSIQUE],
        'echauffement'         => ['Échauffement',             self::GROUPE_PHYSIQUE],
        'retour-calme'         => ['Retour au calme',          self::GROUPE_PHYSIQUE],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $libelle = '';

    #[ORM\Column(length: 60)]
    private string $slug = '';

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::GROUPES)]
    private string $groupe = self::GROUPE_ATTAQUE;

    /**
     * Thème système = pré-défini via migration, non supprimable.
     * Thème custom = créé par l'admin du club, lié à ce club.
     */
    #[ORM\Column]
    private bool $isSysteme = false;

    /**
     * Null pour les thèmes système (partagés globalement).
     * Non null pour les thèmes custom d'un club.
     */
    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLibelle(): string { return $this->libelle; }
    public function setLibelle(string $l): self { $this->libelle = $l; return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $s): self { $this->slug = $s; return $this; }
    public function getGroupe(): string { return $this->groupe; }
    public function setGroupe(string $g): self { $this->groupe = $g; return $this; }
    public function isSysteme(): bool { return $this->isSysteme; }
    public function setIsSysteme(bool $b): self { $this->isSysteme = $b; return $this; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $c): self { $this->club = $c; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function __toString(): string { return $this->libelle; }
}
