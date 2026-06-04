<?php

namespace App\Entity\Sport;

use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\Club;
use App\Repository\Sport\JoueurBadgeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * JoueurBadge — instance d'un badge débloqué par une joueuse.
 *
 * Le catalogue de badges (libellé, icône, axe, critère) est statique dans
 * App\Gamification\BadgeCatalog. Cette entité ne stocke QUE le code du badge
 * + le joueur + la saison + la date. Aucun nom en base : si on change le
 * libellé du badge, tous les badges déjà débloqués reçoivent le nouveau nom
 * automatiquement (pas de désynchro).
 *
 * Unicité métier : un joueur ne peut débloquer chaque badge qu'une fois
 * PAR SAISON (un "Modèle 2024-25" + un "Modèle 2025-26" = 2 entrées).
 * Pour les badges hors-saison (ex: "Première séance"), saison = null et
 * l'unicité reste sur (joueur, codeBadge) — un seul "Première séance" à vie.
 */
#[ORM\Entity(repositoryClass: JoueurBadgeRepository::class)]
#[ORM\Table(name: 'sport_joueur_badge')]
#[ORM\UniqueConstraint(name: 'uniq_joueur_badge_saison', columns: ['joueur_id', 'code_badge', 'saison'])]
#[ORM\Index(name: 'idx_joueur_saison', columns: ['joueur_id', 'saison'])]
class JoueurBadge implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /**
     * Code badge dans BadgeCatalog (ex: 'FIRST_TRAINING', 'STREAK_10').
     * String et pas enum pour rester rétro-compatible quand on ajoute des badges.
     */
    #[ORM\Column(length: 50)]
    private ?string $codeBadge = null;

    /**
     * Saison sportive où le badge a été débloqué (format "YYYY-YYYY").
     * Null pour les badges hors-saison (ex: première séance, vétéran 50).
     */
    #[ORM\Column(length: 9, nullable: true)]
    private ?string $saison = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $debloqueAt = null;

    public function __construct(Joueur $joueur, string $codeBadge, ?string $saison = null)
    {
        $this->joueur = $joueur;
        $this->codeBadge = $codeBadge;
        $this->saison = $saison;
        $this->debloqueAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function getCodeBadge(): ?string { return $this->codeBadge; }
    public function getSaison(): ?string { return $this->saison; }
    public function getDebloqueAt(): ?\DateTimeImmutable { return $this->debloqueAt; }

    /**
     * Multi-tenant : on remonte le club via le joueur.
     * Le ClubVoter utilise ça pour vérifier l'accès.
     */
    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }
}
