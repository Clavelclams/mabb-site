<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\FeedbackSeanceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le retour d'une joueuse sur une séance : une note de 0 à 5, un commentaire.
 *
 * ANONYMAT
 * --------
 * Quand la joueuse coche "anonyme", joueur_id vaut NULL. Pas masqué à l'affichage :
 * absent de la base. Personne ne peut remonter à elle, ni le coach, ni le staff,
 * ni une requête SQL.
 *
 * L'ancienne version stockait joueur_id "pour l'anti-doublon technique" en
 * promettant l'anonymat à l'écran. C'était faux. Le besoin d'anti-doublon est réel,
 * mais il est désormais servi par une table séparée, feedback_participation, qui
 * sait QUI a répondu sans savoir QUOI.
 *
 * L'horodatage est volontairement grossier (jour, pas seconde) : sinon on relierait
 * un commentaire anonyme à une participation en comparant les heures.
 *
 * Ce que ça ne protège pas, et qu'il faut savoir : si une seule joueuse répond à une
 * séance, le simple fait qu'elle ait répondu révèle ce qu'elle a écrit. Aucun schéma
 * n'y peut rien. C'est pourquoi la vue coach n'affiche rien sous 3 réponses.
 */
#[ORM\Entity(repositoryClass: FeedbackSeanceRepository::class)]
#[ORM\Table(name: 'feedback_seance')]
class FeedbackSeance
{
    public const NOTE_MIN = 0;
    public const NOTE_MAX = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Seance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    /**
     * NULL dès que est_anonyme vaut true. En base, pas seulement à l'écran.
     * Ne jamais réintroduire d'écriture de joueur_id sur un retour anonyme :
     * c'est toute la promesse faite à des mineures qui tomberait.
     */
    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Joueur $joueur = null;

    #[ORM\Column(type: 'smallint')]
    #[Assert\Range(min: self::NOTE_MIN, max: self::NOTE_MAX)]
    private int $note = 3;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private bool $estAnonyme = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        // Jour, pas seconde. Un horodatage précis permettrait de rapprocher un
        // retour anonyme d'une ligne de feedback_participation par comparaison
        // des heures. Le coach n'a aucun besoin de la minute.
        $this->createdAt = new \DateTimeImmutable('today');
    }

    public function getId(): ?int { return $this->id; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): self { $this->seance = $s; return $this; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getNote(): int { return $this->note; }
    public function setNote(int $n): self { $this->note = max(self::NOTE_MIN, min(self::NOTE_MAX, $n)); return $this; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $c): self { $this->commentaire = $c; return $this; }
    public function isAnonyme(): bool { return $this->estAnonyme; }
    public function setEstAnonyme(bool $a): self { $this->estAnonyme = $a; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
