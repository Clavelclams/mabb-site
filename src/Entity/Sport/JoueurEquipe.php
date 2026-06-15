<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\JoueurEquipeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * JoueurEquipe — affectation d'un joueur à une équipe pour une saison donnée.
 *
 * POURQUOI CETTE ENTITÉ (V1.6, 15/06/2026) :
 *   Avant cette V, le modèle était One-to-Many strict : 1 joueur = 1 équipe.
 *   Impossible de modéliser le SURCLASSEMENT FFBB (U15 qui joue aussi en U18,
 *   par exemple). Les stats d'un match U18 d'une joueuse U15B n'étaient pas
 *   liées à elle dans EvaluationMatch → bilan saison faussé.
 *
 *   Cette entité ajoute une couche Many-to-Many ENRICHIE (avec attributs
 *   type/saison/actif), sans casser le code existant qui se base sur
 *   Joueur.equipe (= équipe principale gardée pour rétrocompat).
 *
 * MODÈLE :
 *   - Joueur.equipe → Equipe PRINCIPALE (rétrocompat, navigation par défaut)
 *   - Joueur.affectations → Collection<JoueurEquipe> (toutes ses équipes,
 *     dont la principale ET les surclassements)
 *
 * INVARIANT :
 *   Une joueuse a EXACTEMENT 1 affectation "principale" et 0..N affectations
 *   "surclassement" par saison. La principale doit toujours correspondre à
 *   Joueur.equipe (synchro via service ou listener).
 *
 * QUERIES TYPIQUES :
 *   - "Quelles joueuses jouent dans cette équipe ?" → JOIN sur joueur_equipe
 *     où equipe_id = X AND saison = Y AND actif = true
 *   - "Quels matchs cette joueuse a joué ?" → toutes les Rencontre dont
 *     equipe_id ∈ affectations (principale + surclassement)
 *   - "Bilan saison de cette joueuse" → SUM(EvaluationMatch) sur toutes
 *     les rencontres de toutes ses équipes
 */
#[ORM\Entity(repositoryClass: JoueurEquipeRepository::class)]
#[ORM\Table(name: 'joueur_equipe')]
#[ORM\UniqueConstraint(
    name: 'uniq_joueur_equipe_saison',
    columns: ['joueur_id', 'equipe_id', 'saison']
)]
#[ORM\Index(name: 'idx_joueur_equipe_saison_actif', columns: ['saison', 'actif'])]
#[ORM\HasLifecycleCallbacks]
class JoueurEquipe
{
    /** Affectation type : équipe de référence du joueur. */
    public const TYPE_PRINCIPALE = 'principale';

    /** Surclassement : joueur autorisé à jouer aussi dans une autre équipe (catégorie supérieure typiquement). */
    public const TYPE_SURCLASSEMENT = 'surclassement';

    public const TYPES = [self::TYPE_PRINCIPALE, self::TYPE_SURCLASSEMENT];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class, inversedBy: 'affectations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'affectations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = self::TYPE_PRINCIPALE;

    /** Format ISO : "2025-2026". Doit matcher Equipe.saison pour cohérence. */
    #[ORM\Column(length: 9)]
    #[Assert\Regex('/^\d{4}-\d{4}$/')]
    private ?string $saison = null;

    /**
     * Permet de "désactiver" un surclassement sans le supprimer (ex: blessure
     * d'une joueuse U15 qui ne peut plus surclasser en U18 ce semestre).
     * Les vues filtreront par défaut sur actif = true.
     */
    #[ORM\Column]
    private bool $actif = true;

    /**
     * Note optionnelle : motif du surclassement, autorisation du coach principal,
     * date début/fin si limité dans le temps, etc.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Type d\'affectation invalide "%s". Attendu : %s.',
                $type,
                implode(', ', self::TYPES)
            ));
        }
        $this->type = $type;
        return $this;
    }

    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(string $saison): static { $this->saison = $saison; return $this; }

    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /** Helper : est-ce un surclassement (pas l'équipe principale) ? */
    public function isSurclassement(): bool
    {
        return $this->type === self::TYPE_SURCLASSEMENT;
    }

    /** Helper : est-ce l'équipe principale ? */
    public function isPrincipale(): bool
    {
        return $this->type === self::TYPE_PRINCIPALE;
    }
}
