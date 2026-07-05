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

    /**
     * [B23 12/06/2026 — V2.2 25/06/2026] Type de rencontre :
     * - OFFICIEL              : championnat, coupe FFBB (par défaut)
     * - AMICAL                : match d'opposition hors compétition (ex: tournoi amical)
     * - ENTRAINEMENT_INTERNE  : match d'entraînement entre joueuses du club
     *   (multi-catégorie possible — ex U15+U18+Sénior mélangées)
     * - EXHIBITION            : scrimmage avec joueuses non officielles (recrutement,
     *   open gym, début de saison). Accepte les Joueur.isTemporaire des deux côtés.
     */
    public const TYPE_OFFICIEL              = 'OFFICIEL';
    public const TYPE_AMICAL                = 'AMICAL';
    public const TYPE_ENTRAINEMENT_INTERNE  = 'ENTRAINEMENT_INTERNE';
    public const TYPE_EXHIBITION            = 'EXHIBITION';
    public const TYPES_RENCONTRE = [
        self::TYPE_OFFICIEL,
        self::TYPE_AMICAL,
        self::TYPE_ENTRAINEMENT_INTERNE,
        self::TYPE_EXHIBITION,
    ];

    /**
     * [V2.2] Mode de saisie des stats live.
     *   full  = toutes les actions FIBA (tirs, rebonds, passes, fautes, contres…)
     *   light = points + rebonds + passes seulement (suffisant pour open gym/recrutement)
     *   none  = pas de stats live (pure rencontre de planning, juste présences)
     */
    public const MODE_STATS_FULL  = 'full';
    public const MODE_STATS_LIGHT = 'light';
    public const MODE_STATS_NONE  = 'none';
    public const MODES_STATS = [
        self::MODE_STATS_FULL,
        self::MODE_STATS_LIGHT,
        self::MODE_STATS_NONE,
    ];

    #[ORM\Column(length: 30)]
    private string $typeRencontre = self::TYPE_OFFICIEL;

    /**
     * [V2.2] Mode de saisie des stats live. Défaut : full.
     * Pour les exhibitions légères, passer en 'light' pour simplifier l'interface.
     */
    #[ORM\Column(length: 10, options: ['default' => 'full'])]
    private string $modeStats = self::MODE_STATS_FULL;

    /**
     * [B23 12/06/2026] Joueuses externes à l'équipe officielle (hors effectif équipe).
     * Stockées en JSON array d'IDs : [12, 47, 89]. Permet match multi-catégorie
     * sans changer l'effectif officiel des équipes.
     *
     * Distincts des Joueur.isTemporaire (V2.2) qui sont des entités Joueur créées
     * rapidement — plus flexibles pour les stats live.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $joueursExternes = null;

    /**
     * B19 : champs FFBB import.
     * - numeroMatch : "5", "10", "33" (n° dans la division FFBB)
     * - codeEMarque : "KQ7B388D" (code feuille de match e-Marque V2)
     * - saison      : "2025-2026"
     * - division    : "PRF", "PRM", "U18F", etc.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numeroMatch = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $codeEMarque = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $saison = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $division = null;

    #[ORM\Column]
    private bool $forfaitEquipe = false;

    #[ORM\Column]
    private bool $forfaitAdverse = false;

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
    // [B22b-bis 14/06/2026] VALIDATION MANUELLE STATS FFBB
    // ====================================================================
    //
    // Les PDFs FFBB sont des rendus visuels SCANNÉS (image, pas de texte
    // extractible sans OCR). Au lieu de parser, le coach/staff confirme
    // manuellement après le match : "J'ai comparé ma saisie EvaluationMatch
    // avec le PDF FFBB officiel, c'est cohérent". Trace tracée pour le PIRB.
    //
    // Pourquoi pas une table dédiée :
    //   - 1 seule validation par rencontre (pas de 1-N à modéliser)
    //   - Cas typique : nullable au début, rempli quand coach valide
    //   - Évite jointure inutile sur la page rencontre PIRB
    // ====================================================================

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $ffbbStatsValidatedAt = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\Core\User::class)]
    #[ORM\JoinColumn(name: 'ffbb_stats_validated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\Core\User $ffbbStatsValidatedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $ffbbStatsValidationNote = null;

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

    // ====================================================================
    // [V2.3 05/07/2026] MATCH INTERNE À DEUX ÉQUIPES
    // ====================================================================
    //
    // Pour un ENTRAINEMENT_INTERNE ou un AMICAL intra-club, l'effectif du
    // club est réparti en deux équipes A et B stattées SIMULTANÉMENT sur
    // le même écran live (sidebar coupée en 2 colonnes).
    //
    // POURQUOI UN JSON ET PAS UNE TABLE PIVOT (décision ADR-0008) :
    //   - Précédent établi dans CETTE entité : joueursNonConvoques et
    //     joueursExternes sont déjà des JSON — cohérence de conception.
    //   - La donnée est strictement scoped à UNE rencontre, jamais requêtée
    //     en SQL cross-rencontres (le rendu 2 colonnes et la validation se
    //     font en PHP). Une table pivot n'apporterait que des jointures.
    //   - Les stats ne dépendent PAS de ce champ : chaque ActionMatch reste
    //     rattachée à la joueuse + à la rencontre (dont typeRencontre).
    //     La composition est une donnée d'AFFICHAGE/ORGANISATION du live.
    //
    // Structure :
    //   {
    //     "equipeA": {"nom": "Équipe A", "joueurs": [12, 47]},
    //     "equipeB": {"nom": "Équipe B", "joueurs": [33, 89]}
    //   }
    // Invariant : une joueuse est dans A OU B, jamais les deux
    // (garanti par setCompositionInterne()).
    //
    // NULL = rencontre classique (une seule liste, comportement inchangé).
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $compositionInterne = null;

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

    // ====== [B22b-bis 14/06/2026] Validation manuelle Stats FFBB ======
    public function getFfbbStatsValidatedAt(): ?\DateTimeImmutable { return $this->ffbbStatsValidatedAt; }
    public function setFfbbStatsValidatedAt(?\DateTimeImmutable $d): static { $this->ffbbStatsValidatedAt = $d; return $this; }

    public function getFfbbStatsValidatedBy(): ?\App\Entity\Core\User { return $this->ffbbStatsValidatedBy; }
    public function setFfbbStatsValidatedBy(?\App\Entity\Core\User $u): static { $this->ffbbStatsValidatedBy = $u; return $this; }

    public function getFfbbStatsValidationNote(): ?string { return $this->ffbbStatsValidationNote; }
    public function setFfbbStatsValidationNote(?string $note): static { $this->ffbbStatsValidationNote = $note; return $this; }

    /**
     * Helper : True si le coach a explicitement validé les stats FFBB.
     * Utilisé côté PIRB pour afficher le badge "✓ Stats officielles validées".
     */
    public function isFfbbStatsValidated(): bool
    {
        return $this->ffbbStatsValidatedAt !== null;
    }

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

    /**
     * [B22 — 12/06/2026] True si le match est déjà passé (date du coup d'envoi < maintenant).
     * Sert à bloquer les inscriptions/désinscriptions aux rôles officiels et les
     * réponses aux convocations une fois le match terminé.
     *
     * On ajoute volontairement une marge de 4h après le début du match pour couvrir
     * la durée du match + le post-match (rangement, douches, table de marque).
     * Au-delà, plus aucune inscription "Je m'inscris" n'a de sens.
     */
    public function isPassee(): bool
    {
        if ($this->date === null) {
            return false;
        }
        $finEstimee = $this->date->modify('+4 hours');
        return $finEstimee < new \DateTimeImmutable();
    }

    // === B19 : Getters/Setters FFBB import ===

    public function getNumeroMatch(): ?string { return $this->numeroMatch; }
    public function setNumeroMatch(?string $n): self { $this->numeroMatch = $n; return $this; }
    public function getCodeEMarque(): ?string { return $this->codeEMarque; }
    public function setCodeEMarque(?string $c): self { $this->codeEMarque = $c; return $this; }
    public function getSaison(): ?string { return $this->saison; }
    public function setSaison(?string $s): self { $this->saison = $s; return $this; }
    public function getDivision(): ?string { return $this->division; }
    public function setDivision(?string $d): self { $this->division = $d; return $this; }
    public function isForfaitEquipe(): bool { return $this->forfaitEquipe; }
    public function setForfaitEquipe(bool $f): self { $this->forfaitEquipe = $f; return $this; }
    public function isForfaitAdverse(): bool { return $this->forfaitAdverse; }
    public function setForfaitAdverse(bool $f): self { $this->forfaitAdverse = $f; return $this; }

    // === B23 + V2.2 : type rencontre + mode stats + joueuses externes ===
    public function getTypeRencontre(): string { return $this->typeRencontre; }
    public function setTypeRencontre(string $t): self { $this->typeRencontre = $t; return $this; }
    public function getJoueursExternes(): ?array { return $this->joueursExternes; }
    public function setJoueursExternes(?array $j): self { $this->joueursExternes = $j; return $this; }
    public function isEntrainementInterne(): bool { return $this->typeRencontre === self::TYPE_ENTRAINEMENT_INTERNE; }
    public function isAmical(): bool { return $this->typeRencontre === self::TYPE_AMICAL; }
    public function isOfficiel(): bool { return $this->typeRencontre === self::TYPE_OFFICIEL; }
    public function isExhibition(): bool { return $this->typeRencontre === self::TYPE_EXHIBITION; }

    /** True si la rencontre est de type "souple" (amical, entraînement ou exhibition). */
    public function isNonOfficielle(): bool
    {
        return in_array($this->typeRencontre, [
            self::TYPE_AMICAL,
            self::TYPE_ENTRAINEMENT_INTERNE,
            self::TYPE_EXHIBITION,
        ], true);
    }

    public function getModeStats(): string { return $this->modeStats; }
    public function setModeStats(string $mode): self
    {
        if (!in_array($mode, self::MODES_STATS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Mode stats invalide : "%s". Valeurs autorisées : full, light, none.', $mode
            ));
        }
        $this->modeStats = $mode;
        return $this;
    }

    /** True si des stats live peuvent être saisies pour cette rencontre. */
    public function accepteStatsLive(): bool { return $this->modeStats !== self::MODE_STATS_NONE; }
    /** True si le mode light est activé (interface simplifiée). */
    public function isModeLightStats(): bool { return $this->modeStats === self::MODE_STATS_LIGHT; }

    // ====================================================================
    // [V2.3 05/07/2026] Composition interne A/B — helpers
    // ====================================================================

    /**
     * True si la rencontre est un match interne à DEUX équipes du club :
     * type non officiel + composition A/B renseignée avec au moins
     * une joueuse de chaque côté. C'est CE test qui bascule l'écran live
     * en mode 2 colonnes — pas le typeRencontre seul (un entraînement
     * interne "classique" sans composition garde l'écran habituel).
     */
    public function isInterneDeuxEquipes(): bool
    {
        // Même garde que peutComposerDeuxEquipes() : si le staff requalifie
        // la rencontre en OFFICIEL/EXHIBITION après coup, l'écran live
        // retombe automatiquement en mode classique (la composition JSON
        // reste en base mais devient inerte — pas de suppression de donnée).
        return $this->peutComposerDeuxEquipes()
            && count($this->getEquipeAIds()) > 0
            && count($this->getEquipeBIds()) > 0;
    }

    /** True si le type de rencontre autorise la composition A/B. */
    public function peutComposerDeuxEquipes(): bool
    {
        return in_array($this->typeRencontre, [
            self::TYPE_ENTRAINEMENT_INTERNE,
            self::TYPE_AMICAL,
        ], true);
    }

    public function getCompositionInterne(): ?array { return $this->compositionInterne; }

    /**
     * Enregistre la composition A/B avec garantie d'EXCLUSIVITÉ :
     * si un ID apparaît dans les deux équipes, il est retiré de B
     * (A prioritaire — comportement déterministe et documenté plutôt
     * qu'une exception, pour ne jamais perdre une saisie live en cours).
     *
     * @param int[] $idsA IDs joueuses équipe A
     * @param int[] $idsB IDs joueuses équipe B
     */
    public function setCompositionInterne(array $idsA, array $idsB, ?string $nomA = null, ?string $nomB = null): self
    {
        $a = array_values(array_unique(array_map('intval', $idsA)));
        $b = array_values(array_unique(array_map('intval', $idsB)));
        // Exclusivité : une joueuse ne peut pas être dans A ET B
        $b = array_values(array_diff($b, $a));
        sort($a);
        sort($b);

        if ($a === [] && $b === []) {
            $this->compositionInterne = null; // composition vide = retour au mode classique
            return $this;
        }

        $this->compositionInterne = [
            'equipeA' => ['nom' => $nomA !== null && trim($nomA) !== '' ? trim($nomA) : 'Équipe A', 'joueurs' => $a],
            'equipeB' => ['nom' => $nomB !== null && trim($nomB) !== '' ? trim($nomB) : 'Équipe B', 'joueurs' => $b],
        ];
        return $this;
    }

    public function viderCompositionInterne(): self
    {
        $this->compositionInterne = null;
        return $this;
    }

    /** @return int[] IDs des joueuses de l'équipe A (toujours des int). */
    public function getEquipeAIds(): array
    {
        return array_map('intval', $this->compositionInterne['equipeA']['joueurs'] ?? []);
    }

    /** @return int[] IDs des joueuses de l'équipe B. */
    public function getEquipeBIds(): array
    {
        return array_map('intval', $this->compositionInterne['equipeB']['joueurs'] ?? []);
    }

    public function getEquipeANom(): string
    {
        return $this->compositionInterne['equipeA']['nom'] ?? 'Équipe A';
    }

    public function getEquipeBNom(): string
    {
        return $this->compositionInterne['equipeB']['nom'] ?? 'Équipe B';
    }

    /**
     * Côté ('A'|'B'|null) d'une joueuse dans la composition interne.
     * null = joueuse hors composition (ou rencontre classique).
     */
    public function coteJoueur(int $joueurId): ?string
    {
        if (in_array($joueurId, $this->getEquipeAIds(), true)) { return 'A'; }
        if (in_array($joueurId, $this->getEquipeBIds(), true)) { return 'B'; }
        return null;
    }

    /** True si la joueuse fait partie de la composition A∪B. */
    public function estDansComposition(int $joueurId): bool
    {
        return $this->coteJoueur($joueurId) !== null;
    }
}
