<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\EquipeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Équipe d'un club pour une saison donnée.
 *
 * Multi-tenant strict : chaque équipe appartient à UN club (club_id NOT NULL).
 * Cohérent avec ADR-0003 (toute table métier porte un club_id).
 *
 * Exemples :
 *   - MABB / U13 Féminine A / Saison 2025-2026 / Régional
 *   - MABB / Senior Masculine 3x3 / Saison 2025-2026 / Loisir
 */
#[ORM\Entity(repositoryClass: EquipeRepository::class)]
#[ORM\Table(name: 'equipe')]
#[ORM\HasLifecycleCallbacks]
class Equipe implements ClubAwareInterface
{
    public const CATEGORIES = ['U7','U9','U11','U13','U14','U15','U16','U17','U18','Senior F','Senior H','Loisir Mixte'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::CATEGORIES, message: 'Catégorie invalide.')]
    private ?string $categorie = null;

    /** Format ISO : "2025-2026" */
    #[ORM\Column(length: 9)]
    #[Assert\Regex('/^\d{4}-\d{4}$/')]
    private ?string $saison = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $niveau = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Joueur> */
    #[ORM\OneToMany(targetEntity: Joueur::class, mappedBy: 'equipe')]
    private Collection $joueurs;

    /** @var Collection<int, Seance> */
    #[ORM\OneToMany(targetEntity: Seance::class, mappedBy: 'equipe', cascade: ['remove'])]
    private Collection $seances;

    /** @var Collection<int, Rencontre> */
    #[ORM\OneToMany(targetEntity: Rencontre::class, mappedBy: 'equipe', cascade: ['remove'])]
    private Collection $rencontres;

    /**
     * [V1.6 — 15/06/2026] Affectations multi-équipes (surclassement FFBB).
     *
     * Inclut TOUTES les joueuses affectées à cette équipe : celles dont c'est
     * l'équipe principale + celles qui surclassent depuis une autre équipe.
     *
     * Différence avec $joueurs : $joueurs ne contient QUE les joueuses dont
     * Joueur.equipe pointe vers cette équipe (= équipe principale uniquement).
     *
     * Pour avoir le roster COMPLET d'une équipe (incluant surclassements),
     * utiliser $affectations (filtrer par actif=true).
     *
     * @var Collection<int, JoueurEquipe>
     */
    #[ORM\OneToMany(targetEntity: JoueurEquipe::class, mappedBy: 'equipe', cascade: ['remove'])]
    private Collection $affectations;

    /**
     * Les coachs de cette équipe.
     *
     * Sans ce lien, le rôle COACH ne veut rien dire de précis : un coach voit tout
     * le club, et personne n'est responsable d'une séance en particulier. C'est ce
     * qui empêchait de dire "voici TES entraînements" et "voici qui n'a pas fait
     * l'appel".
     *
     * Plusieurs coachs par équipe (principal et adjoint), et un coach peut suivre
     * plusieurs équipes. D'où le ManyToMany.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'equipe_coach')]
    #[ORM\JoinColumn(name: 'equipe_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $coachs;

    public function __construct()
    {
        $this->joueurs = new ArrayCollection();
        $this->seances = new ArrayCollection();
        $this->rencontres = new ArrayCollection();
        $this->affectations = new ArrayCollection();
        $this->coachs = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }
    public function getCategorie(): ?string { return $this->categorie; }
    public function setCategorie(string $categorie): static { $this->categorie = $categorie; return $this; }
    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(string $saison): static { $this->saison = $saison; return $this; }
    public function getNiveau(): ?string { return $this->niveau; }
    public function setNiveau(?string $niveau): static { $this->niveau = $niveau; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getJoueurs(): Collection { return $this->joueurs; }
    public function getSeances(): Collection { return $this->seances; }
    public function getRencontres(): Collection { return $this->rencontres; }

    /** @return Collection<int, JoueurEquipe> */
    public function getAffectations(): Collection { return $this->affectations; }

    /** @return Collection<int, User> */
    public function getCoachs(): Collection { return $this->coachs; }

    public function addCoach(User $user): static
    {
        if (!$this->coachs->contains($user)) {
            $this->coachs->add($user);
        }

        return $this;
    }

    public function removeCoach(User $user): static
    {
        $this->coachs->removeElement($user);

        return $this;
    }

    /**
     * Cet utilisateur coache-t-il cette équipe ?
     *
     * Volontairement sur l'entité et non dans un service : c'est une question sur
     * l'équipe, elle sait y répondre seule. Le Voter et les contrôleurs s'en servent
     * pour décider ce qu'un coach voit et ce dont il répond.
     */
    public function estCoache(?User $user): bool
    {
        return $user !== null && $this->coachs->contains($user);
    }

    /** Pour l'affichage : « Karim et Sofia » plutôt qu'une liste vide. */
    public function getNomsCoachs(): string
    {
        if ($this->coachs->isEmpty()) {
            return '';
        }

        return implode(', ', array_map(
            static fn (User $u): string => trim($u->getPrenom() . ' ' . $u->getNom()),
            $this->coachs->toArray()
        ));
    }

    /**
     * Helper métier : roster complet (Joueur[]) de l'équipe sur la saison de
     * référence (Equipe.saison), incluant les surclassements.
     *
     * Pour des cas avancés (saison différente, filtres custom), utiliser
     * directement JoueurEquipeRepository::joueusesParEquipeSaison().
     *
     * @return Joueur[]
     */
    public function getJoueusesRosterComplet(): array
    {
        $joueuses = [];
        foreach ($this->affectations as $aff) {
            if (!$aff->isActif()) continue;
            if ($aff->getSaison() !== $this->saison) continue;
            $j = $aff->getJoueur();
            if ($j !== null) {
                $joueuses[$j->getId()] = $j; // dédoublonnage par id
            }
        }
        return array_values($joueuses);
    }
}
