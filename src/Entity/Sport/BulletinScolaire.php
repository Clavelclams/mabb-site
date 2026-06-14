<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\User;
use App\Repository\Sport\BulletinScolaireRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * [B33 12/06/2026] Bulletin scolaire d'une joueuse Section Sportive.
 *
 * 1 ligne par trimestre par année. Source : upload manuel (V1) ou import API
 * Pronote/EcoleDirecte (V2 quand le collège ouvre).
 */
#[ORM\Entity(repositoryClass: BulletinScolaireRepository::class)]
#[ORM\Table(name: 'bulletin_scolaire')]
#[ORM\UniqueConstraint(name: 'UNQ_BS_JOUEUR_ANNEE_TRIM', columns: ['joueur_id', 'annee_scolaire', 'trimestre'])]
class BulletinScolaire
{
    public const TRIMESTRE_T1 = 'T1';
    public const TRIMESTRE_T2 = 'T2';
    public const TRIMESTRE_T3 = 'T3';
    public const TRIMESTRES = [self::TRIMESTRE_T1, self::TRIMESTRE_T2, self::TRIMESTRE_T3];

    public const SOURCE_MANUEL          = 'manuel';
    public const SOURCE_API_PRONOTE     = 'api_pronote';
    public const SOURCE_API_ECOLEDIRECTE = 'api_ecoledirecte';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Ex: "2026-2027" */
    #[ORM\Column(length: 9)]
    private string $anneeScolaire = '';

    #[ORM\Column(length: 10)]
    private string $trimestre = self::TRIMESTRE_T1;

    /** Path relatif vers le PDF/image uploadé. NULL si bulletin import API sans fichier. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(nullable: true)]
    private ?float $moyenneGenerale = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $appreciationGlobale = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $uploadedAt = null;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_MANUEL;

    /** @var Collection<int, NoteScolaire> */
    #[ORM\OneToMany(targetEntity: NoteScolaire::class, mappedBy: 'bulletin', cascade: ['persist', 'remove'])]
    private Collection $notes;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
        $this->notes = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getAnneeScolaire(): string { return $this->anneeScolaire; }
    public function setAnneeScolaire(string $a): self { $this->anneeScolaire = $a; return $this; }
    public function getTrimestre(): string { return $this->trimestre; }
    public function setTrimestre(string $t): self { $this->trimestre = $t; return $this; }
    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $p): self { $this->filePath = $p; return $this; }
    public function getMoyenneGenerale(): ?float { return $this->moyenneGenerale; }
    public function setMoyenneGenerale(?float $m): self { $this->moyenneGenerale = $m; return $this; }
    public function getAppreciationGlobale(): ?string { return $this->appreciationGlobale; }
    public function setAppreciationGlobale(?string $a): self { $this->appreciationGlobale = $a; return $this; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $u): self { $this->uploadedBy = $u; return $this; }
    public function getUploadedAt(): ?\DateTimeImmutable { return $this->uploadedAt; }
    public function getSource(): string { return $this->source; }
    public function setSource(string $s): self { $this->source = $s; return $this; }
    public function getNotes(): Collection { return $this->notes; }
    public function addNote(NoteScolaire $note): self
    {
        if (!$this->notes->contains($note)) {
            $this->notes->add($note);
            $note->setBulletin($this);
        }
        return $this;
    }
}
