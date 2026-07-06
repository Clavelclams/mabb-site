<?php

namespace App\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Repository\Sport\JoueurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Joueuse du club. ENTITÉ DÉCOUPLÉE DE USER : un joueur peut ne PAS avoir
 * de compte utilisateur (beaucoup de jeunes licenciées ne s'inscrivent jamais
 * sur l'app), et un User peut être rattaché à plusieurs joueuses (parents).
 *
 * Cette séparation est inspirée du pattern Participant de VEA — éviter le
 * piège "pas de compte = pas de joueuse".
 */
#[ORM\Entity(repositoryClass: JoueurRepository::class)]
#[ORM\Table(name: 'joueur')]
#[ORM\HasLifecycleCallbacks]
class Joueur implements ClubAwareInterface
{
    public const POSTES = ['Meneuse','Arrière','Ailière','Intérieure','Polyvalente'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Club::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Club $club = null;

    /** Affectation équipe — peut être null (joueuse en attente d'équipe) */
    #[ORM\ManyToOne(targetEntity: Equipe::class, inversedBy: 'joueurs')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Equipe $equipe = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $prenom = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Choice(choices: self::POSTES, message: 'Poste invalide.', match: true)]
    private ?string $poste = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Assert\Range(min: 0, max: 99)]
    private ?int $numeroMaillot = null;

    /**
     * Numéro de licence FFBB.
     * UNIQUE depuis V1.3 (07/06/2026) — empêche les doublons et facilite
     * le match auto User↔Joueur au signup PIRB. MySQL accepte plusieurs
     * NULL donc les joueurs sans licence ne sont pas bloqués.
     */
    #[ORM\Column(length: 20, nullable: true, unique: true)]
    private ?string $licence = null;

    /**
     * Email du joueur — clé secondaire de match User ↔ Joueur au signup
     * (utilisée si pas de licence ou si le user s'inscrit sans renseigner sa licence).
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(message: 'Email invalide.')]
    private ?string $email = null;

    /**
     * Téléphone du joueur — clé tertiaire de match User ↔ Joueur au signup
     * (utilisée pour les bénévoles/joueurs jeunes sans email).
     * Format libre, normalisation côté code (strip espaces/points/tirets) avant comparaison.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /**
     * Notes libres : infos medicales, contact urgence, observations coach.
     * Confidentiel — visible uniquement par le staff (CLUB_STAFF+).
     * Important : ces donnees peuvent contenir des infos sensibles (sante)
     * — ne PAS les exposer dans des vues publiques ou API.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * Chemin relatif du fichier photo dans public/uploads/joueurs/.
     * Null = pas de photo, fallback initiales colorées dans la vue.
     *
     * Format : "joueur_{id}_{uniqid}.{ext}" — uniqid évite path traversal et collisions.
     * Le fichier physique est géré par JoueurPhotoUploader, pas par cette entité.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    /**
     * Bio courte du joueur (style Instagram) — V1.2a PIRB Scouting.
     * Affichée sur le profil public si profilPublic=true.
     * Max ~500 caractères (texte libre).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    /**
     * Profil public scouting — V1.2a.
     * Si true : le profil de la joueuse est visible aux scouts/recruteurs
     * via route /pirb/joueuse/{id} (style Instagram, anonyme jusqu'à opt-in).
     * Default : FALSE = anonyme par défaut, la joueuse doit opt-in.
     *
     * Hommage à @pirb_scouting (Pierre) — l'outil que MABB construit pour
     * faciliter le scouting digital du basket français.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $profilPublic = false;

    /**
     * Liens vers les réseaux sociaux de la joueuse — V1.2a.
     * Structure : {instagram, tiktok, youtube, twitter, linkedin}
     * URL complète. Champ libre, null = pas de réseau.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $liensSociaux = null;

    /**
     * Badges épinglés sur le profil PIRB — V1.2b.
     * Max 3 codes badges choisis par la joueuse parmi ceux qu'elle a débloqués.
     * Codes pointant vers BadgeCatalog (ex: 'A_STREAK_10').
     *
     * @var string[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $badgesEpingles = null;

    /**
     * Highlights vidéo — V1.2c. Max 5 liens vers YouTube/Instagram/TikTok.
     * Structure : array de {url, titre, date}.
     * URL validée (filter_var) côté setter.
     *
     * @var array<int, array{url: string, titre: string, date: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $highlights = null;

    /** Lien optionnel vers un compte User */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    // ====================================================================
    // [V2.2 — 25/06/2026] JOUEUSES ÉPHÉMÈRES
    //
    // Une joueuse éphémère est créée rapidement pour une seule rencontre
    // (recrutement, open gym, match d'exhibition, début de saison).
    // Elle est un vrai Joueur dans la BDD pour réutiliser 100% du moteur
    // ActionMatch / stats live existant — pas de table supplémentaire.
    //
    // Workflow de vie :
    //   1. Coach clique "Ajouter joueuse rapide" sur une rencontre exhibition
    //   2. Formulaire minimal : prénom, nom, numéro, côté (notre équipe/adversaire)
    //   3. Joueur créé avec isTemporaire=true, rencontreOrigine=cette rencontre
    //   4. Elle apparaît dans les stats live avec badge coloré
    //   5. Si recrutée : bouton "Recruter" → isTemporaire=false → elle devient officielle
    //
    // Règles :
    //   - Les listes normales de joueurs filtrent isTemporaire=false (transparence)
    //   - Les stats live d'une rencontre chargent aussi les éphémères de CETTE rencontre
    //   - La suppression d'une rencontre met rencontreOrigine à NULL (SET NULL)
    //   - Si équipe adverse : equipeEphemere = nom de l'équipe adverse
    // ====================================================================

    /**
     * Joueuse créée rapidement pour une seule rencontre (pas de compte, pas de licence).
     * Les listes normales de joueuses filtrent isTemporaire=false.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isTemporaire = false;

    /**
     * NULL = joueuse de notre équipe (ou side non précisé).
     * STRING = nom de l'équipe adverse pour laquelle elle joue.
     * Ex: "Eagles Basket Amiens", "Équipe B"
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $equipeEphemere = null;

    /**
     * Couleur de maillot pour distinguer visuellement en stats live.
     * Format libre : code hex (#ef4444) ou nom couleur (rouge, bleu…).
     * Affiché comme pastille colorée dans l'interface stats live.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $couleurMaillot = null;

    /**
     * Rencontre qui a créé cette joueuse éphémère.
     * NULL si la joueuse a été recrutée (isTemporaire=false) ou si la rencontre a été supprimée.
     */
    #[ORM\ManyToOne(targetEntity: Rencontre::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Rencontre $rencontreOrigine = null;

    #[ORM\Column]
    private bool $isActive = true;

    /** [B33 12/06/2026] Tag Section Sportive — débloque module bulletins scolaires. */
    #[ORM\Column]
    private bool $estSectionSportive = false;

    /** [B33] Classe scolaire en cours (ex: "6e A", "5e B"). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $classeScolaire = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Presence> */
    #[ORM\OneToMany(targetEntity: Presence::class, mappedBy: 'joueur', cascade: ['remove'])]
    private Collection $presences;

    /** @var Collection<int, Convocation> */
    #[ORM\OneToMany(targetEntity: Convocation::class, mappedBy: 'joueur', cascade: ['remove'])]
    private Collection $convocations;

    /**
     * [V1.6 — 15/06/2026] Affectations multi-équipes (surclassement FFBB).
     *
     * Joueur peut être affecté à plusieurs équipes via JoueurEquipe : 1
     * affectation "principale" + 0..N "surclassement" par saison.
     *
     * IMPORTANT : Joueur.equipe reste l'équipe principale par défaut pour
     * la rétrocompat du code existant. Les nouvelles features (bilan saison
     * multi-équipes, convocations cross-équipes) utilisent affectations.
     *
     * @var Collection<int, JoueurEquipe>
     */
    #[ORM\OneToMany(targetEntity: JoueurEquipe::class, mappedBy: 'joueur', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $affectations;

    public function __construct()
    {
        $this->presences = new ArrayCollection();
        $this->convocations = new ArrayCollection();
        $this->affectations = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getClub(): ?Club { return $this->club; }
    public function setClub(?Club $club): static { $this->club = $club; return $this; }
    public function getEquipe(): ?Equipe { return $this->equipe; }
    public function setEquipe(?Equipe $equipe): static { $this->equipe = $equipe; return $this; }
    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }
    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }
    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $d): static { $this->dateNaissance = $d; return $this; }
    public function getPoste(): ?string { return $this->poste; }
    public function setPoste(?string $poste): static { $this->poste = $poste; return $this; }
    public function getNumeroMaillot(): ?int { return $this->numeroMaillot; }
    public function setNumeroMaillot(?int $n): static { $this->numeroMaillot = $n; return $this; }
    public function getLicence(): ?string { return $this->licence; }
    public function setLicence(?string $licence): static { $this->licence = $licence; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static
    {
        $this->email = $email ? strtolower(trim($email)) : null;
        return $this;
    }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $tel): static { $this->telephone = $tel; return $this; }

    /**
     * Téléphone normalisé pour comparaison : sans espaces, points, tirets, parenthèses.
     * "+33 6 12 34 56 78" et "0612345678" donnent la même chose (ou presque).
     */
    public function getTelephoneNormalise(): ?string
    {
        if ($this->telephone === null) return null;
        $clean = preg_replace('/[\s\.\-\(\)]/', '', $this->telephone);
        // Convertit +33X en 0X si format français
        if (str_starts_with($clean, '+33')) {
            $clean = '0' . substr($clean, 3);
        }
        return $clean;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    public function getPhotoPath(): ?string { return $this->photoPath; }
    public function setPhotoPath(?string $photoPath): static { $this->photoPath = $photoPath; return $this; }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): static { $this->bio = $bio !== null ? trim($bio) : null; return $this; }

    public function isProfilPublic(): bool { return $this->profilPublic; }
    public function setProfilPublic(bool $v): static { $this->profilPublic = $v; return $this; }

    /** @return array<string, string>|null */
    public function getLiensSociaux(): ?array { return $this->liensSociaux; }
    /** @param array<string, string>|null $liens */
    public function setLiensSociaux(?array $liens): static
    {
        // Filtre : ne garde que les URL non vides + nettoie les clés
        if ($liens === null) {
            $this->liensSociaux = null;
            return $this;
        }
        $clean = [];
        foreach ($liens as $k => $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                $clean[strtolower((string) $k)] = $url;
            }
        }
        $this->liensSociaux = $clean === [] ? null : $clean;
        return $this;
    }
    public function getLienSocial(string $reseau): ?string
    {
        return $this->liensSociaux[strtolower($reseau)] ?? null;
    }

    /** @return array<int, array{url: string, titre: string, date: string}>|null */
    public function getHighlights(): ?array { return $this->highlights; }

    /**
     * Set highlights — V1.2c. Filtre les URLs invalides, tronque à 5 max.
     *
     * @param array<int, array{url?: string, titre?: string, date?: string}>|null $items
     */
    public function setHighlights(?array $items): static
    {
        if ($items === null) {
            $this->highlights = null;
            return $this;
        }
        $clean = [];
        foreach ($items as $i) {
            $url = trim((string) ($i['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $clean[] = [
                'url'   => $url,
                'titre' => trim((string) ($i['titre'] ?? '')) ?: 'Highlight',
                'date'  => trim((string) ($i['date'] ?? '')) ?: '',
            ];
        }
        $this->highlights = array_slice($clean, 0, 5);
        if ($this->highlights === []) {
            $this->highlights = null;
        }
        return $this;
    }

    /** @return string[]|null */
    public function getBadgesEpingles(): ?array { return $this->badgesEpingles; }

    /**
     * Set badges épinglés — V1.2b.
     * Garantit max 3, valeurs uniques, codes non vides.
     *
     * @param string[]|null $codes
     */
    public function setBadgesEpingles(?array $codes): static
    {
        if ($codes === null) {
            $this->badgesEpingles = null;
            return $this;
        }
        $clean = array_values(array_unique(array_filter(
            $codes,
            static fn($c) => is_string($c) && trim($c) !== ''
        )));
        // Tronque à 3 si plus (sécurité, en plus de la validation UI)
        $this->badgesEpingles = array_slice($clean, 0, 3);
        if ($this->badgesEpingles === []) {
            $this->badgesEpingles = null;
        }
        return $this;
    }

    // ====================================================================
    // [V2.4c 06/07/2026] ÉQUIPE PAR SAISON
    //
    // Joueur.equipe est un lien DIRECT (rétrocompat) qui pointe sur la
    // dernière équipe connue — en début de nouvelle saison il montre donc
    // encore l'équipe de la saison PASSÉE, ce qui est faux à l'écran.
    // La vérité saison par saison vit dans les affectations JoueurEquipe.
    // ====================================================================

    /**
     * Équipe PRINCIPALE de la joueuse pour une saison donnée.
     *
     * Ordre de résolution :
     *   1. Affectation JoueurEquipe principale active de cette saison
     *   2. Fallback historique : le lien direct Joueur.equipe, UNIQUEMENT
     *      si son équipe appartient à la saison demandée (couvre les
     *      données d'avant la table pivot)
     *   3. null = pas d'équipe cette saison (ex: saison qui démarre,
     *      équipes pas encore composées)
     */
    public function equipePourSaison(string $saison): ?Equipe
    {
        foreach ($this->affectations as $aff) {
            if ($aff->isActif()
                && $aff->getSaison() === $saison
                && $aff->isPrincipale()) {
                return $aff->getEquipe();
            }
        }
        if ($this->equipe !== null && $this->equipe->getSaison() === $saison) {
            return $this->equipe;
        }
        return null;
    }

    /** True si la joueuse a une équipe (affectation ou legacy) sur la saison. */
    public function aUneEquipeEnSaison(string $saison): bool
    {
        return $this->equipePourSaison($saison) !== null;
    }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    // ====== [V2.2] Joueuses éphémères ======

    public function isTemporaire(): bool { return $this->isTemporaire; }
    public function setIsTemporaire(bool $v): static { $this->isTemporaire = $v; return $this; }

    public function getEquipeEphemere(): ?string { return $this->equipeEphemere; }
    public function setEquipeEphemere(?string $v): static { $this->equipeEphemere = $v !== '' ? $v : null; return $this; }

    /** True si la joueuse éphémère joue pour l'équipe adverse. */
    public function isEphemereAdverse(): bool { return $this->equipeEphemere !== null; }

    public function getCouleurMaillot(): ?string { return $this->couleurMaillot; }
    public function setCouleurMaillot(?string $v): static { $this->couleurMaillot = $v !== '' ? $v : null; return $this; }

    public function getRencontreOrigine(): ?Rencontre { return $this->rencontreOrigine; }
    public function setRencontreOrigine(?Rencontre $r): static { $this->rencontreOrigine = $r; return $this; }

    /**
     * Convertit une joueuse éphémère en joueuse officielle.
     * Appelé par le controller "Recruter" — garde tout l'historique ActionMatch intact.
     *
     * @param Equipe|null $equipe     Affecter à une équipe (null = à affecter plus tard)
     * @param string|null $licence   Numéro FFBB si connu
     */
    public function recruter(?Equipe $equipe = null, ?string $licence = null): static
    {
        $this->isTemporaire      = false;
        $this->equipeEphemere    = null;
        $this->rencontreOrigine  = null;
        if ($equipe !== null) {
            $this->equipe = $equipe;
        }
        if ($licence !== null) {
            $this->licence = $licence;
        }
        return $this;
    }

    // [B33] Section Sportive
    public function isEstSectionSportive(): bool { return $this->estSectionSportive; }
    public function setEstSectionSportive(bool $s): static { $this->estSectionSportive = $s; return $this; }
    public function getClasseScolaire(): ?string { return $this->classeScolaire; }
    public function setClasseScolaire(?string $c): static { $this->classeScolaire = $c; return $this; }
    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getPresences(): Collection { return $this->presences; }
    public function getConvocations(): Collection { return $this->convocations; }

    /** @return Collection<int, JoueurEquipe> */
    public function getAffectations(): Collection { return $this->affectations; }

    /**
     * Ajoute une affectation (en évitant les doublons par référence).
     * Met à jour la relation inverse pour cohérence Doctrine.
     */
    public function addAffectation(JoueurEquipe $affectation): static
    {
        if (!$this->affectations->contains($affectation)) {
            $this->affectations->add($affectation);
            $affectation->setJoueur($this);
        }
        return $this;
    }

    public function removeAffectation(JoueurEquipe $affectation): static
    {
        if ($this->affectations->removeElement($affectation)) {
            if ($affectation->getJoueur() === $this) {
                $affectation->setJoueur(null);
            }
        }
        return $this;
    }

    /**
     * Helper métier : la joueuse est-elle affectée à cette équipe (peu importe
     * que ce soit son équipe principale ou un surclassement) ?
     */
    public function estAffecteeA(Equipe $equipe, ?string $saison = null): bool
    {
        foreach ($this->affectations as $aff) {
            if (!$aff->isActif()) continue;
            if ($aff->getEquipe() !== $equipe) continue;
            if ($saison !== null && $aff->getSaison() !== $saison) continue;
            return true;
        }
        return false;
    }

    /**
     * Helper métier : la joueuse est-elle surclassée dans cette équipe
     * (par opposition à son équipe principale) ?
     */
    public function estSurclasseeDans(Equipe $equipe, ?string $saison = null): bool
    {
        foreach ($this->affectations as $aff) {
            if (!$aff->isActif()) continue;
            if ($aff->getEquipe() !== $equipe) continue;
            if ($saison !== null && $aff->getSaison() !== $saison) continue;
            return $aff->isSurclassement();
        }
        return false;
    }

    public function getNomComplet(): string
    {
        return trim(($this->prenom ?? '') . ' ' . ($this->nom ?? ''));
    }
}
