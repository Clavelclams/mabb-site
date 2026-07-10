<?php

namespace App\Entity\Core;

use App\Repository\Core\UserClubRoleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Table pivot : User <-> Club <-> Rôle métier.
 *
 * UN utilisateur peut avoir PLUSIEURS rôles dans PLUSIEURS clubs.
 * Ex: Jean est COACH dans le club MABB ET PARENT dans le même club.
 *
 * Les rôles métier sont distincts des rôles Symfony (ROLE_USER, ROLE_SUPER_ADMIN).
 * Ils définissent ce que l'utilisateur PEUT FAIRE dans l'interface manager/pirb.
 */
#[ORM\Entity(repositoryClass: UserClubRoleRepository::class)]
#[ORM\Table(name: 'user_club_role')]
#[ORM\UniqueConstraint(name: 'unique_user_club_role', columns: ['user_id', 'club_id', 'role'])]
#[ORM\HasLifecycleCallbacks]
class UserClubRole
{
    // Rôles métier disponibles — enum PHP 8.1+ pour éviter les typos
    public const ROLE_DIRIGEANT  = 'DIRIGEANT';
    public const ROLE_COACH      = 'COACH';
    public const ROLE_STAFF      = 'STAFF';
    public const ROLE_JOUEUR     = 'JOUEUR';
    public const ROLE_PARENT     = 'PARENT';
    public const ROLE_BENEVOLE   = 'BENEVOLE';
    /**
     * EMPLOYE : salarié, alternant ou service civique du club.
     * Distinct des autres rôles : leurs missions "dans le cadre du poste"
     * ne donnent pas d'XP bénévolat (sinon dévalorisation des vrais bénévoles).
     * À la place, ils alimentent un compteur d'XP performance employé
     * (Axe D gamification) — sert au président pour voir qui bosse le plus
     * et élire l'employé du mois / SC du mois.
     */
    public const ROLE_EMPLOYE    = 'EMPLOYE';

    /**
     * TRESORIER : seul rôle habilité à voir, saisir et valider les opérations
     * de trésorerie du club (Bureau Phase D). Distinct de DIRIGEANT pour permettre
     * qu'un trésorier non-membre du CA puisse exister, et inversement (un dirigeant
     * lambda ne voit pas la compta). Un user PEUT cumuler DIRIGEANT + TRESORIER
     * via 2 UserClubRole distincts.
     */
    public const ROLE_TRESORIER  = 'TRESORIER';

    /**
     * SECRETAIRE : gestion administrative du club (dossiers licences, relances
     * paiements, coordonnées parents, organisation des week-ends de match).
     * Distinct de DIRIGEANT : la secrétaire n'a PAS accès à la trésorerie ni
     * aux outils coach. Un user peut cumuler (2 UserClubRole distincts).
     * [V2.4g 09/07/2026 — chantier Dashboard Secrétaire]
     */
    public const ROLE_SECRETAIRE = 'SECRETAIRE';

    public const ROLES_DISPONIBLES = [
        self::ROLE_DIRIGEANT,
        self::ROLE_COACH,
        self::ROLE_STAFF,
        self::ROLE_JOUEUR,
        self::ROLE_PARENT,
        self::ROLE_BENEVOLE,
        self::ROLE_EMPLOYE,
        self::ROLE_TRESORIER,
        self::ROLE_SECRETAIRE,
    ];

    /**
     * Workflow de validation des inscriptions.
     * Un user qui s'inscrit ne devient pas immédiatement membre actif du club —
     * un dirigeant doit valider sa demande. Empêche les inscriptions sauvages
     * et les "voyeurs" qui s'inscriraient dans plusieurs clubs sans légitimité.
     */
    public const STATUS_PENDING  = 'pending';   // demande à valider par dirigeant
    public const STATUS_ACTIVE   = 'active';    // validé, accès normal selon role
    public const STATUS_REJECTED = 'rejected';  // refusé (gardé en BDD pour audit)

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_REJECTED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userClubRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Club::class, inversedBy: 'userClubRoles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /**
     * Rôle métier dans ce club.
     * Valeurs : DIRIGEANT | COACH | STAFF | JOUEUR | PARENT | BENEVOLE
     */
    #[ORM\Column(length: 30)]
    private ?string $role = null;

    /** Le dirigeant peut activer/désactiver un rôle sans le supprimer */
    #[ORM\Column]
    private bool $isActive = true;

    /**
     * Statut de validation : pending → active → (éventuellement) rejected.
     * Défaut "pending" : aucune permission tant que pas validé par un dirigeant.
     * Pour les rows EXISTANTES en BDD avant l'ajout de cette colonne, la
     * migration doit faire un UPDATE pour les passer à 'active' (sinon tous
     * les anciens membres seraient bloqués) — cf. instruction migration.
     */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    /**
     * Rôle SOUHAITÉ à l'inscription (ex: le user a demandé "JOUEUR" mais
     * tant que la demande est pending, son `role` actuel reste BENEVOLE).
     * Quand le dirigeant valide, on bascule `role = roleDemande`.
     * Null si pas de rôle spécifique demandé (= demande "Bénévole" générique).
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $roleDemande = null;

    /** User dirigeant qui a validé/rejeté la demande (audit). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $valideParUser = null;

    /** Date de validation/rejet (null tant que pending). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // Méthodes utilitaires
    // -------------------------------------------------------------------------

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES_DISPONIBLES, true);
    }

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getClub(): ?Club
    {
        return $this->club;
    }

    public function setClub(?Club $club): static
    {
        $this->club = $club;
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        if (!self::isValidRole($role)) {
            throw new \InvalidArgumentException(sprintf(
                'Rôle "%s" invalide. Rôles acceptés : %s',
                $role,
                implode(', ', self::ROLES_DISPONIBLES)
            ));
        }
        $this->role = $role;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ====== Workflow de validation ======

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Statut invalide : $status");
        }
        $this->status = $status;
        return $this;
    }

    public function isPending(): bool  { return $this->status === self::STATUS_PENDING; }
    public function isStatusActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }

    public function getRoleDemande(): ?string { return $this->roleDemande; }
    public function setRoleDemande(?string $r): static
    {
        if ($r !== null && !self::isValidRole($r)) {
            throw new \InvalidArgumentException("Rôle demandé invalide : $r");
        }
        $this->roleDemande = $r;
        return $this;
    }

    public function getValideParUser(): ?User { return $this->valideParUser; }
    public function setValideParUser(?User $u): static { $this->valideParUser = $u; return $this; }

    public function getValideAt(): ?\DateTimeImmutable { return $this->valideAt; }
    public function setValideAt(?\DateTimeImmutable $d): static { $this->valideAt = $d; return $this; }

    /**
     * Action métier : un dirigeant valide la demande.
     * Bascule status=active, applique le roleDemande si présent, trace l'auditeur.
     */
    public function valider(?User $par, ?string $roleFinal = null): static
    {
        // Tolère $par null : si la session Symfony retourne un user bizarre,
        // on garde l'action métier (status → active) sans crasher l'app.
        // L'audit perd la trace de l'auteur dans ce cas edge, mais c'est moins
        // grave que de bloquer la validation entière.
        $this->status = self::STATUS_ACTIVE;
        $this->valideParUser = $par;
        $this->valideAt = new \DateTimeImmutable();
        if ($roleFinal !== null && self::isValidRole($roleFinal)) {
            $this->role = $roleFinal;
        } elseif ($this->roleDemande !== null) {
            $this->role = $this->roleDemande;
        }
        return $this;
    }

    /**
     * Action métier : un dirigeant rejette la demande.
     */
    public function rejeter(?User $par): static
    {
        // Idem valider() : tolère $par null pour éviter de bloquer l'action métier
        $this->status = self::STATUS_REJECTED;
        $this->valideParUser = $par;
        $this->valideAt = new \DateTimeImmutable();
        return $this;
    }
}
