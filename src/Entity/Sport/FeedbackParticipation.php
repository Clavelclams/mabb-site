<?php

declare(strict_types=1);

namespace App\Entity\Sport;

use App\Repository\Sport\FeedbackParticipationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace le FAIT qu'une joueuse a répondu à une séance. Rien d'autre.
 *
 * Cette table existe pour une seule raison : permettre l'anonymat réel des
 * retours de séance.
 *
 * Le problème qu'elle résout
 * --------------------------
 * On a besoin de savoir qui a déjà répondu (anti-doublon, badges). Mais si on
 * range cette information sur la même ligne que la note et le commentaire, alors
 * "anonyme" est un mensonge : n'importe qui avec un accès à la base relie le
 * commentaire à son autrice.
 *
 * La solution
 * -----------
 * On coupe en deux.
 *   - feedback_participation (ici)  : QUI a répondu. Aucune note, aucun texte.
 *   - feedback_seance               : QUOI a été répondu. joueur_id à NULL si anonyme.
 *
 * Aucune requête ne peut relier les deux. Pas de clé commune, pas de jointure.
 *
 * Ce qui reste possible, et qu'il faut assumer
 * --------------------------------------------
 * Si une seule joueuse répond à une séance, savoir qu'elle a répondu revient à
 * savoir ce qu'elle a écrit. C'est arithmétique, aucun schéma n'y échappe. C'est
 * pour ça que la vue coach n'affiche RIEN en dessous de 3 réponses
 * (SEUIL_AFFICHAGE dans FeedbackSeanceRepository).
 */
#[ORM\Entity(repositoryClass: FeedbackParticipationRepository::class)]
#[ORM\Table(name: 'feedback_participation')]
#[ORM\UniqueConstraint(name: 'uniq_participation_joueur_seance', columns: ['joueur_id', 'seance_id'])]
class FeedbackParticipation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Joueur::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Joueur $joueur = null;

    #[ORM\ManyToOne(targetEntity: Seance::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Seance $seance = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getJoueur(): ?Joueur { return $this->joueur; }
    public function setJoueur(?Joueur $j): self { $this->joueur = $j; return $this; }
    public function getSeance(): ?Seance { return $this->seance; }
    public function setSeance(?Seance $s): self { $this->seance = $s; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
