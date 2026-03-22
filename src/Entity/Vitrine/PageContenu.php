<?php

namespace App\Entity\Vitrine;

use App\Repository\Vitrine\PageContenuRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PageContenuRepository::class)]
#[ORM\Table(name: 'page_contenu')]
#[ORM\HasLifecycleCallbacks]
class PageContenu
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Identifiant unique de la page — ex: 'projet-sport-etude', 'formation', 'numerique'
    #[ORM\Column(length: 100, unique: true)]
    private ?string $pageSlug = null;

    // Label lisible pour l'admin — ex: 'Projet Sport-Études'
    #[ORM\Column(length: 255)]
    private ?string $pageNom = null;

    // Contenu Markdown éditable
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $contenu = null;

    // Sous-titre optionnel de la page
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sousTitre = null;

    // Chemin de l'image de couverture — ex: 'pages/formation.jpg'
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    // Couleur du texte du header — ex: '#ffffff', '#063a55'
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $couleurTexte = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getPageSlug(): ?string { return $this->pageSlug; }
    public function setPageSlug(string $s): static { $this->pageSlug = $s; return $this; }

    public function getPageNom(): ?string { return $this->pageNom; }
    public function setPageNom(string $n): static { $this->pageNom = $n; return $this; }

    public function getContenu(): ?string { return $this->contenu; }
    public function setContenu(?string $c): static { $this->contenu = $c; return $this; }

    public function getSousTitre(): ?string { return $this->sousTitre; }
    public function setSousTitre(?string $s): static { $this->sousTitre = $s; return $this; }

    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $p): static { $this->imagePath = $p; return $this; }

    public function getCouleurTexte(): ?string { return $this->couleurTexte; }
    public function setCouleurTexte(?string $c): static { $this->couleurTexte = $c; return $this; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
