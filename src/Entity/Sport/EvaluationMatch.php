<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Repository\Sport\EvaluationMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * EvaluationMatch — performance d'une joueuse sur une rencontre.
 *
 * UNE éval = UN joueur × UNE rencontre. Contrainte d'unicité au niveau BDD.
 *
 * POURQUOI UNE TABLE SÉPARÉE DE PRESENCE :
 *   Presence répond à "qui était là ?" (binary).
 *   EvaluationMatch répond à "comment elle a joué ?" (multi-dimensionnel, ~15 compteurs).
 *   Mélanger les deux casse Single Responsibility et gonfle Presence avec des
 *   colonnes vides pour les séances (95% des cas). Normalisation 3NF respectée.
 *
 * FORMULE FIBA (calculée à la volée par getEval()) :
 *   EVAL = (Points + Rebonds + Passes + Interceptions + Contres + Fautes provoquées)
 *        − (Tirs ratés + Lancers ratés + Pertes de balle + Fautes commises + Contres subis)
 *
 * MULTI-TENANT :
 *   Implémente ClubAwareInterface en délégant à $joueur->getClub() (même pattern
 *   que Presence). Le ClubVoter protège automatiquement les opérations CRUD.
 */
#[ORM\Entity(repositoryClass: EvaluationMatchRepository::class)]
#[ORM\Table(name: 'evaluation_match')]
#[ORM\UniqueConstraint(name: 'unique_joueur_rencontre', columns: ['joueur_id', 'rencontre_id'])]
#[ORM\HasLifecycleCallbacks]
class EvaluationMatch implements ClubAwareInterface
{
    // ====================================================================
    // [V2.4p] SOURCE DE LA DONNÉE — d'où vient cette éval ?
    //   - live   : agrégée depuis Stats Live (ActionMatch + PresenceTerrain)
    //   - ffbb   : importée du PDF officiel FFBB (OCR) — QUE points/minutes/
    //              LF/fautes réussis, PAS de rebonds/passes/tirs tentés
    //   - manuel : saisie du coach (formulaire /evals ou import Excel)
    // Sert au toggle « Stats Live / FFBB » de la fiche joueuse : on ne
    // mélange plus une éval FFBB incomplète (sous-estimée) avec une éval
    // live complète dans la même moyenne.
    // ====================================================================
    public const SOURCE_LIVE   = 'live';
    public const SOURCE_FFBB   = 'ffbb';
    public const SOURCE_MANUEL = 'manuel';
    public const SOURCES = [self::SOURCE_LIVE, self::SOURCE_FFBB, self::SOURCE_MANUEL];

    /**
     * Groupe « vision club » du toggle : le live ET la saisie coach sont
     * des données riches produites par le club — la FFBB est à part.
     */
    public const SOURCES_CLUB = [self::SOURCE_LIVE, self::SOURCE_MANUEL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ====================================================================
    // RELATIONS — joueur × rencontre, unicité au niveau BDD
    // ====================================================================

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Rencontre $rencontre = null;

    // ====================================================================
    // MÉTADONNÉES DU MATCH POUR CETTE JOUEUSE
    // ====================================================================

    /**
     * [V2.4p] Provenance de la donnée (voir constantes SOURCE_*).
     * Défaut 'manuel' : c'est le cas historique le plus sûr (une éval dont
     * on ignore l'origine est traitée comme une saisie coach, jamais comme
     * une officielle FFBB).
     */
    #[ORM\Column(length: 10, options: ['default' => self::SOURCE_MANUEL])]
    #[Assert\Choice(choices: self::SOURCES)]
    private string $source = self::SOURCE_MANUEL;

    /** Titulaire (5 majeur) ou remplaçante ? */
    #[ORM\Column]
    private bool $isStarter = false;

    /** Minutes effectivement jouées (utile pour les ratios "par minute") */
    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: 0, max: 60)]
    private int $minutesJouees = 0;

    // ====================================================================
    // COMPTEURS DE TIR — distinction tentés/réussis (= % de tir calculable)
    // ====================================================================

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $tirs2ptsReussis = 0;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $tirs2ptsTentes = 0;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $tirs3ptsReussis = 0;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $tirs3ptsTentes = 0;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $lancersReussis = 0;

    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $lancersTentes = 0;

    // ====================================================================
    // COMPTEURS DE JEU (FIBA)
    // ====================================================================

    /** Rebonds offensifs (sur tir manqué par son équipe) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $rebondsOffensifs = 0;

    /** Rebonds défensifs (sur tir manqué par l'adversaire) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $rebondsDefensifs = 0;

    /** Passes décisives (assist menant directement à un panier) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $passesDecisives = 0;

    /** Interceptions (steals) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $interceptions = 0;

    /** Contres réussis (blocks) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $contres = 0;

    /** Contres SUBIS (entre dans la formule en négatif) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $contresSubis = 0;

    /** Fautes commises par la joueuse */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $fautesCommises = 0;

    /** Fautes provoquées (l'adversaire a fauté sur elle) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $fautesProvoquees = 0;

    /** Pertes de balle (turnovers) */
    #[ORM\Column(type: 'smallint')]
    #[Assert\PositiveOrZero]
    private int $pertesBalle = 0;

    // ====================================================================
    // FEEDBACK HUMAIN — optionnel, à l'appréciation du coach
    // ====================================================================

    /**
     * Note libre du coach pour CETTE joueuse sur CE match.
     * Pas seulement chiffres — l'humain compte. Visible joueuse + staff.
     * (Si confidentiel, à mettre dans Joueur::notes plutôt)
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notesCoach = null;

    // ====================================================================
    // TIMESTAMPS
    // ====================================================================

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ====================================================================
    // MULTI-TENANT — ClubAwareInterface : on délègue à joueur->club
    // ====================================================================

    public function getClub(): ?Club
    {
        return $this->joueur?->getClub();
    }

    // ====================================================================
    // MÉTHODES CALCULÉES — formule FIBA + agrégats
    // ====================================================================

    /**
     * Points totaux marqués = 2 × tirs 2pts + 3 × tirs 3pts + 1 × lancers
     */
    public function getPoints(): int
    {
        return ($this->tirs2ptsReussis * 2)
             + ($this->tirs3ptsReussis * 3)
             + $this->lancersReussis;
    }

    /**
     * Rebonds totaux = offensifs + défensifs
     */
    public function getRebonds(): int
    {
        return $this->rebondsOffensifs + $this->rebondsDefensifs;
    }

    /**
     * Tirs ratés totaux (entre dans la formule FIBA en négatif).
     * = (tentés - réussis) sur 2pts + 3pts
     */
    public function getTirsRates(): int
    {
        return ($this->tirs2ptsTentes - $this->tirs2ptsReussis)
             + ($this->tirs3ptsTentes - $this->tirs3ptsReussis);
    }

    public function getLancersRates(): int
    {
        return $this->lancersTentes - $this->lancersReussis;
    }

    /**
     * Pourcentage de réussite aux tirs du terrain (hors lancers francs).
     * Retourne null si :
     *   - aucun tir tenté (évite division par zéro)
     *   - tirs réussis > tirs tentés (incohérence : la FFBB ne donne que les
     *     réussis via OCR, donc les "tentés" peuvent rester à 0 ou à une vieille
     *     valeur, donnant des % aberrants type 3000%). Les % ne sont fiables
     *     qu'avec Stats Live qui capture aussi les tirs manqués.
     */
    public function getPourcentageTirs(): ?float
    {
        $tentes = $this->tirs2ptsTentes + $this->tirs3ptsTentes;
        if ($tentes === 0) {
            return null;
        }
        $reussis = $this->tirs2ptsReussis + $this->tirs3ptsReussis;
        // Garde-fou cohérence : on ne peut pas avoir plus de réussis que de tentés
        if ($reussis > $tentes) {
            return null;
        }
        return round($reussis / $tentes * 100, 1);
    }

    /**
     * FORMULE FIBA OFFICIELLE — l'évaluation globale du match.
     *
     * EVAL = (Points + Rebonds + Passes + Interceptions + Contres + Fautes provoquées)
     *      − (Tirs ratés + Lancers ratés + Pertes de balle + Fautes commises + Contres subis)
     *
     * Référence : règlement FFBB / FIBA Performance Index Rating (PIR).
     * Une joueuse avec EVAL >= 15 sur un match est considérée comme dominante,
     * 5-15 = solide, < 5 = match en demi-teinte, négatif = match raté.
     */
    public function getEval(): int
    {
        $positif = $this->getPoints()
                 + $this->getRebonds()
                 + $this->passesDecisives
                 + $this->interceptions
                 + $this->contres
                 + $this->fautesProvoquees;

        $negatif = $this->getTirsRates()
                 + $this->getLancersRates()
                 + $this->pertesBalle
                 + $this->fautesCommises
                 + $this->contresSubis;

        return $positif - $negatif;
    }

    // ====================================================================
    // GETTERS / SETTERS
    // ====================================================================

    public function getId(): ?int { return $this->id; }

    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $joueur): static { $this->joueur = $joueur; return $this; }

    public function getRencontre(): ?Rencontre { return $this->rencontre; }
    public function setRencontre(?Rencontre $rencontre): static { $this->rencontre = $rencontre; return $this; }

    public function isStarter(): bool { return $this->isStarter; }
    public function setIsStarter(bool $isStarter): static { $this->isStarter = $isStarter; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): static
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Source d'évaluation invalide : $source");
        }
        $this->source = $source;
        return $this;
    }
    public function isSourceFfbb(): bool { return $this->source === self::SOURCE_FFBB; }
    public function isSourceLive(): bool { return $this->source === self::SOURCE_LIVE; }

    public function getMinutesJouees(): int { return $this->minutesJouees; }
    public function setMinutesJouees(int $minutes): static { $this->minutesJouees = $minutes; return $this; }

    public function getTirs2ptsReussis(): int { return $this->tirs2ptsReussis; }
    public function setTirs2ptsReussis(int $n): static { $this->tirs2ptsReussis = $n; return $this; }

    public function getTirs2ptsTentes(): int { return $this->tirs2ptsTentes; }
    public function setTirs2ptsTentes(int $n): static { $this->tirs2ptsTentes = $n; return $this; }

    public function getTirs3ptsReussis(): int { return $this->tirs3ptsReussis; }
    public function setTirs3ptsReussis(int $n): static { $this->tirs3ptsReussis = $n; return $this; }

    public function getTirs3ptsTentes(): int { return $this->tirs3ptsTentes; }
    public function setTirs3ptsTentes(int $n): static { $this->tirs3ptsTentes = $n; return $this; }

    public function getLancersReussis(): int { return $this->lancersReussis; }
    public function setLancersReussis(int $n): static { $this->lancersReussis = $n; return $this; }

    public function getLancersTentes(): int { return $this->lancersTentes; }
    public function setLancersTentes(int $n): static { $this->lancersTentes = $n; return $this; }

    public function getRebondsOffensifs(): int { return $this->rebondsOffensifs; }
    public function setRebondsOffensifs(int $n): static { $this->rebondsOffensifs = $n; return $this; }

    public function getRebondsDefensifs(): int { return $this->rebondsDefensifs; }
    public function setRebondsDefensifs(int $n): static { $this->rebondsDefensifs = $n; return $this; }

    public function getPassesDecisives(): int { return $this->passesDecisives; }
    public function setPassesDecisives(int $n): static { $this->passesDecisives = $n; return $this; }

    public function getInterceptions(): int { return $this->interceptions; }
    public function setInterceptions(int $n): static { $this->interceptions = $n; return $this; }

    public function getContres(): int { return $this->contres; }
    public function setContres(int $n): static { $this->contres = $n; return $this; }

    public function getContresSubis(): int { return $this->contresSubis; }
    public function setContresSubis(int $n): static { $this->contresSubis = $n; return $this; }

    public function getFautesCommises(): int { return $this->fautesCommises; }
    public function setFautesCommises(int $n): static { $this->fautesCommises = $n; return $this; }

    public function getFautesProvoquees(): int { return $this->fautesProvoquees; }
    public function setFautesProvoquees(int $n): static { $this->fautesProvoquees = $n; return $this; }

    public function getPertesBalle(): int { return $this->pertesBalle; }
    public function setPertesBalle(int $n): static { $this->pertesBalle = $n; return $this; }

    public function getNotesCoach(): ?string { return $this->notesCoach; }
    public function setNotesCoach(?string $notes): static { $this->notesCoach = $notes; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
}
