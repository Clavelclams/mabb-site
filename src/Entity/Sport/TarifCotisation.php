<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\TarifCotisationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tarif de cotisation pour une CATÉGORIE d'âge dans un CLUB pour une SAISON.
 *
 * Bureau Phase D.3.1 — Tarifs configurables par catégorie.
 *
 * EXEMPLES :
 *   MABB / U13 / 2025-2026 / 180€
 *   MABB / U15 / 2025-2026 / 200€
 *   MABB / Senior F / 2025-2026 / 280€
 *
 * STRUCTURE :
 *   - Une ligne par tuple (club, catégorie, saison) — UNIQUE garanti BDD.
 *   - La catégorie est restreinte à Equipe::CATEGORIES (cohérence cross-entité).
 *   - Pas de tarif par défaut "tous âges" : on définit explicitement chaque
 *     catégorie que le club gère. Si une catégorie n'a pas de tarif défini,
 *     le générateur de cotisations utilise le montant par défaut saisi.
 *
 * POURQUOI lié à la SAISON ?
 *   - Les tarifs évoluent : une augmentation l'année suivante doit pouvoir
 *     coexister avec l'ancien tarif pour l'historique.
 *   - Régénérer les cotisations d'une vieille saison doit utiliser l'ANCIEN
 *     tarif, pas le nouveau.
 *
 * MULTI-TENANT : implémente ClubAwareInterface — chaque club a SES tarifs.
 */
#[ORM\Entity(repositoryClass: TarifCotisationRepository::class)]
#[ORM\Table(name: 'tarif_cotisation')]
#[ORM\UniqueConstraint(name: 'UNIQ_TARIF_CLUB_CAT_SAISON', columns: ['club_id', 'categorie', 'saison'])]
#[ORM\HasLifecycleCallbacks]
class TarifCotisation implements ClubAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Doit être l'une des valeurs de Equipe::CATEGORIES.
     * Pas d'Assert\Choice ici pour éviter le couplage Validator côté entité,
     * mais validé par le controller.
     */
    #[ORM\Column(length: 32)]
    private string $categorie = '';

    /** Format "YYYY-YYYY" (ex: "2025-2026"). */
    #[ORM\Column(length: 9)]
    private string $saison = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $montant = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): self { $this->club = $club; return $this; }

    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $categorie): self
    {
        if (!in_array($categorie, Equipe::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Catégorie invalide : "%s". Attendu : %s.',
                $categorie,
                implode(', ', Equipe::CATEGORIES)
            ));
        }
        $this->categorie = $categorie;
        return $this;
    }

    public function getSaison(): string { return $this->saison; }
    public function setSaison(string $saison): self
    {
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            throw new \InvalidArgumentException(sprintf('Saison invalide : "%s"', $saison));
        }
        $this->saison = $saison;
        return $this;
    }

    public function getMontant(): string { return $this->montant; }
    public function setMontant(string $montant): self
    {
        if (str_starts_with($montant, '-')) {
            throw new \InvalidArgumentException('Le montant doit être positif ou nul.');
        }
        $this->montant = $montant;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
