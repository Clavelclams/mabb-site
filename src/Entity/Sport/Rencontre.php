<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\RencontreRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Rencontre (match) vs un adversaire.
 *
 * Renommé "Rencontre" pour éviter le mot-clé 'match' de PHP 8+.
 *
 * Workflow statut : brouillon -> validé -> verrouillé.
 * Une fois verrouillée, la feuille de match ne peut plus être modifiée
 * sans privilège super_admin (anti-triche après diffusion des résultats).
 */
#[ORM\Entity(repositoryClass: RencontreRepository::class)]
#[ORM\Table(name: 'rencontre')]
#[ORM\HasLifecycleCallbacks]
class Rencontre implements ClubAwareInterface
{
    public const STATUT_BROUILLON  = 'brouillon';
    public const STATUT_VALIDE     = 'valide';
    public const STATUT_VERROUILLE = 'verrouille';
    /**
     * ARCHIVE (V2.1c) : la rencontre n'apparaît plus dans les listes courantes
     * mais reste accessible en BDD et via filtre "Voir les archivées".
     * Utile pour : matchs de test, doublons, anciennes saisies.
     * Toggle réversible (admin peut désarchiver).
     */
    public const STATUT_ARCHIVE    = 'archive';
    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_VALIDE,
        self::STATUT_VERROUILLE,
        self::STATUT_ARCHIVE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'rencontres')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'L\'équipe est obligatoire.')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $adversaire = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $lieu = null;

    #[ORM\Column]
    private bool $domicile = true;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $scoreEquipe = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $scoreAdverse = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS)]
    private ?string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Indique si la FFBB a désigné un arbitre officiel pour ce match.
     * Si oui, aucune inscription bénévole interne n'est possible (le bouton
     * est désactivé dans l'UI). Si non, un membre du club peut s'inscrire
     * comme arbitre bénévole — pratique pour les catégories jeunes (U13, U15)
     * où l'arbitrage est souvent assuré par les clubs.
     */
    #[ORM\Column]
    private bool $arbitreExterneDesigne = false;

    /**
     * Nom de l'arbitre officiel FFBB (si désigné). Champ informatif libre,
     * récupéré depuis la convocation FFBB. Affiché sur la fiche rencontre.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $arbitreExterneNom = null;

    // ====================================================================
    // Documents FFBB officiels — uploadés par le staff après match
    // Stockés dans public/uploads/rencontres/ avec nom sécurisé.
    // Servent à la saisie assistée des évals (PDF résumé à côté du form).
    //
    // Pourquoi 3 champs séparés et pas une table Document:
    //   - Chaque type de document est UNIQUE par rencontre (1 résumé, 1 feuille,
    //     1 positions de tirs). Pas de 1-N à modéliser.
    //   - Champ string suffit (juste le nom du fichier).
    //   - Évite jointures BDD pour un simple chemin.
    // ====================================================================

    /** Chemin relatif du PDF "résumé" FFBB (stats individuelles agrégées). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resumePath = null;

    /** Chemin relatif du PDF "feuille de match" FFBB (table de marque officielle). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $feuilleMatchPath = null;

    /** Chemin relatif du PDF "positions des tirs" FFBB (shot chart). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $positionsTirsPath = null;

    // ====================================================================
    // FORMAT DE MATCH — Stats Live V2.1a
    // ====================================================================
    //
    // Le format détermine le chrono affiché et le nombre de périodes côté UI.
    // Valeurs typiques FFBB féminine :
    //   - Seniors / U18 / U17 : 4 × 10 min (FIBA officiel)
    //   - U15 / U13 / U11     : 4 × 8 min
    //   - Mini-basket U9/U7   : périodes plus courtes (4×6 ou 2×8)
    //   - Loisir              : 2 × 20 min (libre)
    //
    // On ne fige PAS une enum stricte : chaque catégorie a ses spécificités,
    // on laisse 2 entiers libres. Le coach saisit ce que dit le règlement
    // de son championnat.

    /** Nombre de périodes (2 pour mi-temps, 4 pour quart-temps). Défaut : 4. */
    #[ORM\Column(type: 'integer', options: ['default' => 4])]
    private int $nbPeriodes = 4;

    /** Durée d'UNE période en minutes. Défaut : 10. */
    #[ORM\Column(type: 'integer', options: ['default' => 10])]
    private int $dureePeriodeMinutes = 10;

    /**
     * IDs des joueuses NON convoquées au match (V2.1f).
     * Stocké en JSON pour rester simple : pas d'entité dédiée car la donnée
     * est strictement liée à UNE rencontre (pas d'historique multi-saison).
     *
     * Convention : array<int> — IDs des joueuses qui NE jouent PAS aujourd'hui.
     * Les joueuses non listées ici sont par défaut "convoquées".
     *
     * Pourquoi cocher "non convoquée" plutôt que "convoquée" ?
     *   - Par défaut, on convoque TOUT l'effectif actif → moins de clics
     *   - On marque uniquement les exceptions (blessées, examens, etc.)
     *
     * @var int[]
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $joueursNonConvoques = [];

    /**
     * Rôles bénévoles internes (arbitres, marqueur, chrono, e-marque, stats…).
     * Voir entité RencontreRole pour le détail. Chaque rôle peut être pris
     * par un User différent ; la table fait office de planning officiel.
     *
     * @var Collection<int, RencontreRole>
     */
    #[ORM\OneToMany(targetEntity: RencontreRole::class, mappedBy: 'rencontre', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $roles;

    /** @var Collection<int, Convocation> */
    #[ORM\OneToMany(targetEntity: Convocation::class, mappedBy: 'rencontre', cascade: ['remove'])]
    private Collection $convocations;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'rencontre', cascade: ['remove'])]
    private Collection $presences;

    public function __construct()
    {
        $this->convocations = new ArrayCollection();
        $this->presences = new ArrayCollection();
        $this->roles = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void { $this->createdAt = new \DateTimeImmutable(); }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void { $this->updatedAt = new \DateTimeImmutable(); }

    public function isVerrouillee(): bool { return $this->statut === self::STATUT_VERROUILLE; }
    public function isArchivee(): bool { return $this->statut === self::STATUT_ARCHIVE; }
    public function aResultat(): bool { return $this->scoreEquipe !== null && $this->scoreAdverse !== null; }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }
    public function getAdversaire(): ?string { return $this->adversaire; }
    public function setAdversaire(string $adv): static { $this->adversaire = $adv; return $this; }
    public function getDate(): ?\DateTimeImmutable { return $this->date; }
    /**
     * Accepte null pour ne pas crasher en TypeError quand Symfony Form soumet
     * une date vide (l'erreur sera levée par la validation Form, pas par PHP).
     */
    public function setDate(?\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getLieu(): ?string { return $this->lieu; }
    public function setLieu(?string $lieu): static { $this->lieu = $lieu; return $this; }
    public function isDomicile(): bool { return $this->domicile; }
    public function setDomicile(bool $dom): static { $this->domicile = $dom; return $this; }
    public function getScoreEquipe(): ?int { return $this->scoreEquipe; }
    public function setScoreEquipe(?int $s): static { $this->scoreEquipe = $s; return $this; }
    public function getScoreAdverse(): ?int { return $this->scoreAdverse; }
    public function setScoreAdverse(?int $s): static { $this->scoreAdverse = $s; return $this; }
    public function getStatut(): ?string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getConvocations(): Collection { return $this->convocations; }
    public function getPresences(): Collection { return $this->presences; }

    // ====== Arbitrage FFBB ======
    public function isArbitreExterneDesigne(): bool { return $this->arbitreExterneDesigne; }
    public function setArbitreExterneDesigne(bool $v): static { $this->arbitreExterneDesigne = $v; return $this; }

    public function getArbitreExterneNom(): ?string { return $this->arbitreExterneNom; }
    public function setArbitreExterneNom(?string $nom): static { $this->arbitreExterneNom = $nom; return $this; }

    // ====== Documents FFBB ======
    public function getResumePath(): ?string { return $this->resumePath; }
    public function setResumePath(?string $path): static { $this->resumePath = $path; return $this; }

    public function getFeuilleMatchPath(): ?string { return $this->feuilleMatchPath; }
    public function setFeuilleMatchPath(?string $path): static { $this->feuilleMatchPath = $path; return $this; }

    public function getPositionsTirsPath(): ?string { return $this->positionsTirsPath; }
    public function setPositionsTirsPath(?string $path): static { $this->positionsTirsPath = $path; return $this; }

    // === Format match (V2.1a) ===

    public function getNbPeriodes(): int { return $this->nbPeriodes; }
    public function setNbPeriodes(int $n): static
    {
        if (!in_array($n, [2, 4], true)) {
            throw new \InvalidArgumentException('Nombre de périodes : 2 (mi-temps) ou 4 (quart-temps).');
        }
        $this->nbPeriodes = $n;
        return $this;
    }

    public function getDureePeriodeMinutes(): int { return $this->dureePeriodeMinutes; }
    public function setDureePeriodeMinutes(int $m): static
    {
        if ($m < 1 || $m > 30) {
            throw new \InvalidArgumentException('Durée de période : entre 1 et 30 minutes.');
        }
        $this->dureePeriodeMinutes = $m;
        return $this;
    }

    /**
     * Libellé humain du format match — utilisé en UI.
     * Ex : "4 × 10 min" ou "2 × 20 min".
     */
    public function getFormatMatchLabel(): string
    {
        return sprintf('%d × %d min', $this->nbPeriodes, $this->dureePeriodeMinutes);
    }

    /**
     * Durée totale en secondes (pour le chrono final).
     */
    public function getDureeTotaleSecondes(): int
    {
        return $this->nbPeriodes * $this->dureePeriodeMinutes * 60;
    }

    /**
     * @return int[]
     */
    public function getJoueursNonConvoques(): array
    {
        return $this->joueursNonConvoques ?? [];
    }

    /**
     * @param int[] $ids
     */
    public function setJoueursNonConvoques(array $ids): self
    {
        // Force valeurs entières + dédoublonnage + tri pour cohérence
        $clean = array_values(array_unique(array_map('intval', $ids)));
        sort($clean);
        $this->joueursNonConvoques = $clean;
        return $this;
    }

    public function estConvoquee(int $joueurId): bool
    {
        return !in_array($joueurId, $this->getJoueursNonConvoques(), true);
    }

    /**
     * Helper : récupère le path d'un PDF par son type ('resume', 'feuille', 'positions').
     * Évite des if/else dans le controller. Renvoie null si type invalide.
     */
    public function getPdfPath(string $type): ?string
    {
        return match($type) {
            'resume'    => $this->resumePath,
            'feuille'   => $this->feuilleMatchPath,
            'positions' => $this->positionsTirsPath,
            default     => null,
        };
    }

    /**
     * Helper : assigne un path par type. Source de vérité unique pour les types valides.
     */
    public function setPdfPath(string $type, ?string $path): static
    {
        match($type) {
            'resume'    => $this->resumePath = $path,
            'feuille'   => $this->feuilleMatchPath = $path,
            'positions' => $this->positionsTirsPath = $path,
            default     => throw new \InvalidArgumentException("Type de PDF invalide : $type"),
        };
        return $this;
    }

    /** @return Collection<int, RencontreRole> */
    public function getRoles(): Collection { return $this->roles; }

    /**
     * Récupère le RencontreRole pour un rôle donné (ARBITRE_1, MARQUEUR, etc.),
     * ou null si personne n'est inscrit sur ce rôle.
     */
    public function getRoleParCode(string $codeRole): ?RencontreRole
    {
        foreach ($this->roles as $r) {
            if ($r->getRole() === $codeRole) return $r;
        }
        return null;
    }

    /**
     * True si les rôles d'arbitrage interne peuvent être pris par des bénévoles.
     * Faux si la FFBB a désigné un arbitre officiel (case "arbitre externe" cochée).
     */
    public function peutRecevoirArbitreBenevole(): bool
    {
        return !$this->arbitreExterneDesigne;
    }
}
