<?php

declare(strict_types=1);

namespace App\Entity\Vitrine;

use App\Repository\Vitrine\BlocContenuRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * BlocContenu — [CMS V2 05/07/2026] contenu éditable AU NIVEAU BLOC.
 *
 * Complète PageContenu (1 blob Markdown par page) avec une granularité
 * fine : chaque titre, paragraphe, image ou chiffre de la vitrine peut
 * devenir éditable individuellement depuis /admin/contenus.
 *
 * FONCTIONNEMENT :
 *   - Dans un template vitrine : {{ cms('accueil.hero.titre', 'Texte par défaut') }}
 *   - Premier rendu : la clé est AUTO-ENREGISTRÉE en base avec son défaut
 *     → l'admin voit apparaître le bloc dans le back-office sans dev.
 *   - valeur NULL = on affiche le défaut du template (rien n'est perdu,
 *     le template reste la source du contenu initial).
 *
 * CONVENTION DE CLÉ : "{page}.{section}.{champ}"
 *   ex : accueil.hero.titre, accueil.engage.photo, club.histoire.texte
 *   → le préfixe avant le premier point sert de groupe dans l'admin.
 */
#[ORM\Entity(repositoryClass: BlocContenuRepository::class)]
#[ORM\Table(name: 'bloc_contenu')]
#[ORM\HasLifecycleCallbacks]
class BlocContenu
{
    public const TYPE_TEXTE = 'texte';  // court, input simple
    public const TYPE_LONG  = 'long';   // paragraphe, textarea
    public const TYPE_IMAGE = 'image';  // chemin d'upload

    public const TYPES = [self::TYPE_TEXTE, self::TYPE_LONG, self::TYPE_IMAGE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150, unique: true)]
    private string $cle = '';

    /** Groupe d'affichage admin (= préfixe de la clé : "accueil", "club"…). */
    #[ORM\Column(length: 50)]
    private string $page = '';

    #[ORM\Column(length: 10)]
    private string $type = self::TYPE_TEXTE;

    /** Contenu saisi par l'admin. NULL = utiliser le défaut du template. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $valeur = null;

    /** Défaut capturé au premier rendu (référence visible dans l'admin). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $defaut = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getCle(): string { return $this->cle; }
    public function setCle(string $c): static
    {
        $this->cle  = $c;
        $this->page = explode('.', $c)[0] ?: 'divers';
        return $this;
    }

    public function getPage(): string { return $this->page; }

    public function getType(): string { return $this->type; }
    public function setType(string $t): static
    {
        $this->type = in_array($t, self::TYPES, true) ? $t : self::TYPE_TEXTE;
        return $this;
    }

    public function getValeur(): ?string { return $this->valeur; }
    public function setValeur(?string $v): static { $this->valeur = ($v === '' ? null : $v); return $this; }

    public function getDefaut(): ?string { return $this->defaut; }
    public function setDefaut(?string $d): static { $this->defaut = $d; return $this; }

    /** Ce qui doit s'afficher sur le site : valeur admin sinon défaut template. */
    public function rendu(): string { return $this->valeur ?? $this->defaut ?? ''; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
