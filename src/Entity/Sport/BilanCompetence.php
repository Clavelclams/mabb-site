<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Repository\Sport\BilanCompetenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * BilanCompetence — Bilan de compétences basketballistique d'une joueuse.
 *
 * Reproduit fidèlement la fiche Excel "bilan vierge.xlsx" utilisée par
 * les coaches MABB pour évaluer chaque joueuse lors des camps ou bilans
 * de fin de saison.
 *
 * Structure :
 *   4 catégories de critères (22 critères au total, notes 1-10)
 *   + informations administratives
 *   + sidebar (mensurations, participation, profil de jeu)
 *   + champs texte libres (points forts, vigilance, axes, remarques)
 *
 * Grille de lecture :
 *   1-3 : à développer (🔴)
 *   4-6 : à renforcer (🟠)
 *   7-9 : à perfectionner (🟢)
 *   10  : qualité exceptionnelle (⭐)
 *
 * Workflow :
 *   BROUILLON → coach remplit → VALIDE → joueuse peut voir depuis PIRB
 */
#[ORM\Entity(repositoryClass: BilanCompetenceRepository::class)]
#[ORM\Table(name: 'bilan_competence')]
#[ORM\Index(columns: ['joueur_id', 'saison'], name: 'idx_bilan_joueur_saison')]
#[ORM\HasLifecycleCallbacks]
class BilanCompetence
{
    // ── Statuts ─────────────────────────────────────────────────────────────
    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE    = 'valide';

    // ── Contextes prédéfinis ────────────────────────────────────────────────
    public const CONTEXTE_CAMP      = "Camp d'entraînement";
    public const CONTEXTE_FIN       = 'Fin de saison';
    public const CONTEXTE_MI_SAISON = 'Mi-saison';
    public const CONTEXTE_SELECTION = 'Détection / Sélection';

    // ── ID ───────────────────────────────────────────────────────────────────
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ── Relations ────────────────────────────────────────────────────────────
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    /** Coach ayant rempli le bilan (peut être null si importé) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $coach = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    // ── Métadonnées ─────────────────────────────────────────────────────────
    #[ORM\Column(length: 12)]
    private string $saison = '';                 // ex: "2025-2026"

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $contexte = null;            // ex: "Camp d'entraînement"

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEvaluation = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    // ── RENSEIGNEMENTS administratifs ───────────────────────────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $numeroLicence = null;

    #[ORM\Column(length: 25, nullable: true)]
    private ?string $numSecuSociale = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mutuelle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $problemeSante = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $allergies = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $regimeAlimentaire = null;

    // ── Participation / Sidebar ─────────────────────────────────────────────
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $nbSeances = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $presenceType = null;        // "Stage", "Entraînement", …

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $taille = null;                 // en cm

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $poids = null;               // en kg, stocké string pour précision

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $envergure = null;              // en cm

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $tailleAssise = null;           // en cm

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $pointure = null;

    #[ORM\Column(length: 15, nullable: true)]
    private ?string $mainForte = null;           // 'DROITE', 'GAUCHE', 'AMBIDEXTRE'

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profilDeJeu = null;

    // ── Vie quotidienne / Internat (5 critères) ────────────────────────────
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $vqRespectRegles = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $vqPonctualite = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $vqDiscipline = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $vqVieGroupe = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $vqRangement = null;

    // ── Qualités Mentales (6 critères) ──────────────────────────────────────
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmEnthousiasme = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmDetermination = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmConfiance = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmCuriosite = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmAutonomie = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qmConcentration = null;

    // ── Qualités Technico-Tactiques (8 critères) ────────────────────────────
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttAdresse = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttEfficacitePanier = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttAisance = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttJeuSansBallons = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttComprehension = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttDefense = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttRebondCatcher = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qttRebondTransiter = null;

    // ── Qualités Physiques (3 critères) ─────────────────────────────────────
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qpEnchainement = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qpVitesse = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $qpSoinsDuCorps = null;

    // ── Champs texte libres ──────────────────────────────────────────────────
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pointsForts = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alerteMedicale = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pointsVigilance = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $axesTravail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bilanRemarques = null;

    // ── Constructeur ─────────────────────────────────────────────────────────
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Helpers calculés ─────────────────────────────────────────────────────

    /**
     * Retourne tous les scores non-null sous forme de tableau plat.
     * @return int[]
     */
    public function getAllScores(): array
    {
        return array_filter([
            $this->vqRespectRegles, $this->vqPonctualite, $this->vqDiscipline,
            $this->vqVieGroupe, $this->vqRangement,
            $this->qmEnthousiasme, $this->qmDetermination, $this->qmConfiance,
            $this->qmCuriosite, $this->qmAutonomie, $this->qmConcentration,
            $this->qttAdresse, $this->qttEfficacitePanier, $this->qttAisance,
            $this->qttJeuSansBallons, $this->qttComprehension, $this->qttDefense,
            $this->qttRebondCatcher, $this->qttRebondTransiter,
            $this->qpEnchainement, $this->qpVitesse, $this->qpSoinsDuCorps,
        ], fn($v) => $v !== null);
    }

    /** Somme de tous les critères renseignés (max 220 si 22 × 10) */
    public function getScoreTotal(): int
    {
        return (int) array_sum($this->getAllScores());
    }

    /** Nombre de critères renseignés (sur 22 max) */
    public function getNbCriteresRemplis(): int
    {
        return count($this->getAllScores());
    }

    /** Moyenne (null si aucun critère renseigné) */
    public function getMoyenne(): ?float
    {
        $nb = $this->getNbCriteresRemplis();
        if ($nb === 0) return null;
        return round($this->getScoreTotal() / $nb, 1);
    }

    /**
     * Retourne la classe CSS de couleur pour un score.
     * Utilisé dans les templates pour le code couleur de l'Excel.
     */
    public static function couleurScore(?int $note): string
    {
        if ($note === null) return 'score-vide';
        if ($note <= 3) return 'score-rouge';
        if ($note <= 6) return 'score-orange';
        if ($note <= 9) return 'score-vert';
        return 'score-or';
    }

    public function isValide(): bool { return $this->statut === self::STATUT_VALIDE; }
    public function isBrouillon(): bool { return $this->statut === self::STATUT_BROUILLON; }

    public function valider(): static
    {
        $this->statut = self::STATUT_VALIDE;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    // ── Accesseurs (getters/setters) ─────────────────────────────────────────
    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getCoach(): ?User { return $this->coach; }
    public function setCoach(?User $coach): static { $this->coach = $coach; return $this; }

    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }

    public function getSaison(): string { return $this->saison; }
    public function setSaison(string $saison): static { $this->saison = $saison; return $this; }

    public function getContexte(): ?string { return $this->contexte; }
    public function setContexte(?string $contexte): static { $this->contexte = $contexte; return $this; }

    public function getDateEvaluation(): ?\DateTimeImmutable { return $this->dateEvaluation; }
    public function setDateEvaluation(?\DateTimeImmutable $dateEvaluation): static { $this->dateEvaluation = $dateEvaluation; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    // Renseignements
    public function getNumeroLicence(): ?string { return $this->numeroLicence; }
    public function setNumeroLicence(?string $v): static { $this->numeroLicence = $v; return $this; }
    public function getNumSecuSociale(): ?string { return $this->numSecuSociale; }
    public function setNumSecuSociale(?string $v): static { $this->numSecuSociale = $v; return $this; }
    public function getMutuelle(): ?string { return $this->mutuelle; }
    public function setMutuelle(?string $v): static { $this->mutuelle = $v; return $this; }
    public function getProblemeSante(): ?string { return $this->problemeSante; }
    public function setProblemeSante(?string $v): static { $this->problemeSante = $v; return $this; }
    public function getAllergies(): ?string { return $this->allergies; }
    public function setAllergies(?string $v): static { $this->allergies = $v; return $this; }
    public function getRegimeAlimentaire(): ?string { return $this->regimeAlimentaire; }
    public function setRegimeAlimentaire(?string $v): static { $this->regimeAlimentaire = $v; return $this; }

    // Sidebar
    public function getNbSeances(): ?int { return $this->nbSeances; }
    public function setNbSeances(?int $v): static { $this->nbSeances = $v; return $this; }
    public function getPresenceType(): ?string { return $this->presenceType; }
    public function setPresenceType(?string $v): static { $this->presenceType = $v; return $this; }
    public function getTaille(): ?int { return $this->taille; }
    public function setTaille(?int $v): static { $this->taille = $v; return $this; }
    public function getPoids(): ?string { return $this->poids; }
    public function setPoids(?string $v): static { $this->poids = $v; return $this; }
    public function getEnvergure(): ?int { return $this->envergure; }
    public function setEnvergure(?int $v): static { $this->envergure = $v; return $this; }
    public function getTailleAssise(): ?int { return $this->tailleAssise; }
    public function setTailleAssise(?int $v): static { $this->tailleAssise = $v; return $this; }
    public function getPointure(): ?int { return $this->pointure; }
    public function setPointure(?int $v): static { $this->pointure = $v; return $this; }
    public function getMainForte(): ?string { return $this->mainForte; }
    public function setMainForte(?string $v): static { $this->mainForte = $v; return $this; }
    public function getProfilDeJeu(): ?string { return $this->profilDeJeu; }
    public function setProfilDeJeu(?string $v): static { $this->profilDeJeu = $v; return $this; }

    // Vie quotidienne
    public function getVqRespectRegles(): ?int { return $this->vqRespectRegles; }
    public function setVqRespectRegles(?int $v): static { $this->vqRespectRegles = $v; return $this; }
    public function getVqPonctualite(): ?int { return $this->vqPonctualite; }
    public function setVqPonctualite(?int $v): static { $this->vqPonctualite = $v; return $this; }
    public function getVqDiscipline(): ?int { return $this->vqDiscipline; }
    public function setVqDiscipline(?int $v): static { $this->vqDiscipline = $v; return $this; }
    public function getVqVieGroupe(): ?int { return $this->vqVieGroupe; }
    public function setVqVieGroupe(?int $v): static { $this->vqVieGroupe = $v; return $this; }
    public function getVqRangement(): ?int { return $this->vqRangement; }
    public function setVqRangement(?int $v): static { $this->vqRangement = $v; return $this; }

    // Qualités Mentales
    public function getQmEnthousiasme(): ?int { return $this->qmEnthousiasme; }
    public function setQmEnthousiasme(?int $v): static { $this->qmEnthousiasme = $v; return $this; }
    public function getQmDetermination(): ?int { return $this->qmDetermination; }
    public function setQmDetermination(?int $v): static { $this->qmDetermination = $v; return $this; }
    public function getQmConfiance(): ?int { return $this->qmConfiance; }
    public function setQmConfiance(?int $v): static { $this->qmConfiance = $v; return $this; }
    public function getQmCuriosite(): ?int { return $this->qmCuriosite; }
    public function setQmCuriosite(?int $v): static { $this->qmCuriosite = $v; return $this; }
    public function getQmAutonomie(): ?int { return $this->qmAutonomie; }
    public function setQmAutonomie(?int $v): static { $this->qmAutonomie = $v; return $this; }
    public function getQmConcentration(): ?int { return $this->qmConcentration; }
    public function setQmConcentration(?int $v): static { $this->qmConcentration = $v; return $this; }

    // Qualités Technico-Tactiques
    public function getQttAdresse(): ?int { return $this->qttAdresse; }
    public function setQttAdresse(?int $v): static { $this->qttAdresse = $v; return $this; }
    public function getQttEfficacitePanier(): ?int { return $this->qttEfficacitePanier; }
    public function setQttEfficacitePanier(?int $v): static { $this->qttEfficacitePanier = $v; return $this; }
    public function getQttAisance(): ?int { return $this->qttAisance; }
    public function setQttAisance(?int $v): static { $this->qttAisance = $v; return $this; }
    public function getQttJeuSansBallons(): ?int { return $this->qttJeuSansBallons; }
    public function setQttJeuSansBallons(?int $v): static { $this->qttJeuSansBallons = $v; return $this; }
    public function getQttComprehension(): ?int { return $this->qttComprehension; }
    public function setQttComprehension(?int $v): static { $this->qttComprehension = $v; return $this; }
    public function getQttDefense(): ?int { return $this->qttDefense; }
    public function setQttDefense(?int $v): static { $this->qttDefense = $v; return $this; }
    public function getQttRebondCatcher(): ?int { return $this->qttRebondCatcher; }
    public function setQttRebondCatcher(?int $v): static { $this->qttRebondCatcher = $v; return $this; }
    public function getQttRebondTransiter(): ?int { return $this->qttRebondTransiter; }
    public function setQttRebondTransiter(?int $v): static { $this->qttRebondTransiter = $v; return $this; }

    // Qualités Physiques
    public function getQpEnchainement(): ?int { return $this->qpEnchainement; }
    public function setQpEnchainement(?int $v): static { $this->qpEnchainement = $v; return $this; }
    public function getQpVitesse(): ?int { return $this->qpVitesse; }
    public function setQpVitesse(?int $v): static { $this->qpVitesse = $v; return $this; }
    public function getQpSoinsDuCorps(): ?int { return $this->qpSoinsDuCorps; }
    public function setQpSoinsDuCorps(?int $v): static { $this->qpSoinsDuCorps = $v; return $this; }

    // Texte libre
    public function getPointsForts(): ?string { return $this->pointsForts; }
    public function setPointsForts(?string $v): static { $this->pointsForts = $v; return $this; }
    public function getAlerteMedicale(): ?string { return $this->alerteMedicale; }
    public function setAlerteMedicale(?string $v): static { $this->alerteMedicale = $v; return $this; }
    public function getPointsVigilance(): ?string { return $this->pointsVigilance; }
    public function setPointsVigilance(?string $v): static { $this->pointsVigilance = $v; return $this; }
    public function getAxesTravail(): ?string { return $this->axesTravail; }
    public function setAxesTravail(?string $v): static { $this->axesTravail = $v; return $this; }
    public function getBilanRemarques(): ?string { return $this->bilanRemarques; }
    public function setBilanRemarques(?string $v): static { $this->bilanRemarques = $v; return $this; }
}
