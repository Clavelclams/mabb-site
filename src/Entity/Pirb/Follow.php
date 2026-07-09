<?php

declare(strict_types=1);

namespace App\Entity\Pirb;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Sport\Joueur;
use App\Repository\Pirb\FollowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Follow — [Social V1, 09/07/2026] « X suit Y » dans l'app PIRB.
 *
 * PREMIÈRE entité du namespace Pirb : le social est un concept de l'APP
 * (l'espace joueuse), pas du domaine sportif (Sport/) ni du socle (Core/).
 *
 * CHOIX DE MODÈLE : Joueur → Joueur (pas User → User). C'est la FICHE
 * joueuse qui est suivie : une coéquipière sans compte app peut donc déjà
 * avoir des abonnées, et le jour où elle installe l'app, ses compteurs
 * existent. Le User n'est que la porte d'entrée (auth), pas l'identité
 * sociale.
 *
 * RGPD (public mineur) : la règle intra-club est appliquée AU CONTRÔLEUR
 * (PirbFollowController) — on ne peut suivre qu'une joueuse de son club,
 * comme la commu. L'entité, elle, reste générique : le jour où le
 * consentement parental inter-club est cadré, on élargit le contrôleur
 * sans toucher au modèle.
 *
 * Unicité métier : une paire (suiveuse, suivie) n'existe qu'une fois —
 * re-suivre quelqu'un qu'on suit déjà est un no-op, garanti par la base.
 */
#[ORM\Entity(repositoryClass: FollowRepository::class)]
#[ORM\Table(name: 'pirb_follow')]
#[ORM\UniqueConstraint(name: 'uniq_pirb_follow_paire', columns: ['suiveuse_id', 'suivie_id'])]
#[ORM\Index(name: 'idx_pirb_follow_suivie', columns: ['suivie_id'])]
class Follow implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Celle qui appuie sur « Suivre ». */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $suiveuse = null;

    /** Celle qui gagne une abonnée. */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $suivie = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Constructeur sans arguments (hydratation Doctrine). En code
     * applicatif, passer par la factory ::creer() — même convention
     * que JoueurBadge.
     */
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Factory : un follow prêt à persister, lisible en une ligne. */
    public static function creer(Joueur $suiveuse, Joueur $suivie): self
    {
        $f = new self();
        $f->suiveuse = $suiveuse;
        $f->suivie = $suivie;
        return $f;
    }

    public function getId(): ?int { return $this->id; }
    public function getSuiveuse(): ?Joueur { return $this->suiveuse; }
    public function getSuivie(): ?Joueur { return $this->suivie; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /**
     * Multi-tenant : le club de référence est celui de la suiveuse
     * (en V1 intra-club les deux clubs sont identiques de toute façon).
     */
    public function getClub(): ?Club
    {
        return $this->suiveuse?->getClub();
    }
}
