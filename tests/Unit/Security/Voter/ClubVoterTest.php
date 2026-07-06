<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\ClubVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * [P0 jury CDA] Tests du ClubVoter — cœur de l'autorisation multi-tenant.
 *
 * On vérifie que les droits d'un utilisateur sont TOUJOURS liés à un club
 * précis : aucun rôle dans un club ne doit ouvrir de droit dans un autre.
 * Le test central est l'anti-fuite inter-club (testCoachDuClubANeVotePasPourLeClubB).
 *
 * Tests unitaires purs (aucune base de données) : les entités sont montées
 * en mémoire. Les entités n'ayant pas de setId(), on pose des IDs distincts
 * par réflexion — indispensable car le Voter compare les clubs par getId().
 */
final class ClubVoterTest extends TestCase
{
    private ClubVoter $voter;

    protected function setUp(): void
    {
        // TenantResolver réel : sa méthode userBelongsToClub() (utilisée par
        // l'attribut CLUB_MEMBER) est de la logique pure sur l'utilisateur,
        // elle ne touche ni la session ni la base. On lui passe donc des
        // dépendances mockées qui ne seront jamais appelées.
        $resolver = new TenantResolver(
            $this->createStub(RequestStack::class),
            $this->createStub(ClubRepository::class),
            $this->createStub(Security::class),
        );
        $this->voter = new ClubVoter($resolver);
    }

    // ───────────────────────── Helpers de montage ──────────────────────────

    private function club(int $id, string $nom = 'Club'): Club
    {
        $club = (new Club())->setNom($nom . ' ' . $id);
        return $this->setId($club, $id);
    }

    /**
     * Construit un utilisateur avec un rôle dans un club donné.
     */
    private function user(
        int $id,
        Club $club,
        string $role,
        bool $actif = true,
        string $status = UserClubRole::STATUS_ACTIVE,
        array $securityRoles = ['ROLE_USER'],
    ): User {
        $user = (new User())->setEmail('u' . $id . '@test.fr')->setRoles($securityRoles);
        $this->setId($user, $id);

        $ucr = (new UserClubRole())
            ->setClub($club)
            ->setRole($role)
            ->setIsActive($actif)
            ->setStatus($status);
        $user->addUserClubRole($ucr);

        return $user;
    }

    /**
     * Un utilisateur sans aucun rôle club (mais authentifié).
     */
    private function userSansClub(int $id, array $securityRoles = ['ROLE_USER']): User
    {
        $user = (new User())->setEmail('u' . $id . '@test.fr')->setRoles($securityRoles);
        return $this->setId($user, $id);
    }

    private function token(?object $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    /** Pose un ID sur une entité Doctrine (pas de setId côté entité). */
    private function setId(object $entity, int $id): object
    {
        // Depuis PHP 8.1, la réflexion accède aux propriétés privées sans
        // setAccessible() (devenu inutile, et déprécié en 8.5).
        $prop = new \ReflectionProperty($entity::class, 'id');
        $prop->setValue($entity, $id);
        return $entity;
    }

    private function vote(TokenInterface $token, mixed $subject, string $attribute): int
    {
        return $this->voter->vote($token, $subject, [$attribute]);
    }

    // ───────────────────────── supports() / garde-fous ─────────────────────

    public function testAbstientSurAttributNonSupporte(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_DIRIGEANT));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($token, $club, 'ATTRIBUT_INCONNU'),
            'un attribut hors périmètre du Voter doit être ignoré (abstain)'
        );
    }

    public function testAbstientSurSubjetNonClub(): void
    {
        $token = $this->token($this->user(1, $this->club(1), UserClubRole::ROLE_DIRIGEANT));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->vote($token, new \stdClass(), ClubVoter::CLUB_MEMBER),
            'un subject qui n\'est ni Club ni ClubAwareInterface doit être ignoré'
        );
    }

    public function testRefuseSiTokenSansUtilisateur(): void
    {
        $club = $this->club(1);
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($this->token(null), $club, ClubVoter::CLUB_MEMBER),
            'un token anonyme (pas de User) n\'a aucun droit club'
        );
    }

    public function testEntiteClubAwareSansClubRefusee(): void
    {
        // Cas pathologique : entité en cours de construction, club non défini.
        $subject = new class implements ClubAwareInterface {
            public function getClub(): ?Club { return null; }
        };
        $token = $this->token($this->user(1, $this->club(1), UserClubRole::ROLE_DIRIGEANT));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($token, $subject, ClubVoter::CLUB_MEMBER)
        );
    }

    // ───────────────────────── ROLE_SUPER_ADMIN ────────────────────────────

    public function testSuperAdminAAccesAToutClub(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        // Super admin SANS aucun UserClubRole : le court-circuit doit primer.
        $admin = $this->userSansClub(99, ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $token = $this->token($admin);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $clubA, ClubVoter::CLUB_ADMIN));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $clubB, ClubVoter::CLUB_STAFF_ELARGI));
    }

    // ───────────────────────── CLUB_MEMBER ─────────────────────────────────

    public function testMembreValideDeSonClub(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_BENEVOLE));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_MEMBER));
    }

    public function testRoleEnAttenteNeDonneAucunDroit(): void
    {
        // Un UserClubRole "pending" (inscription pas encore validée par un
        // dirigeant) ne doit ouvrir AUCUN accès — anti-voyeur.
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_JOUEUR, true, UserClubRole::STATUS_PENDING));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($token, $club, ClubVoter::CLUB_MEMBER));
    }

    public function testRoleDesactiveNeDonneAucunDroit(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_COACH, false));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($token, $club, ClubVoter::CLUB_MEMBER));
    }

    // ───────────────────────── Rôles spécifiques ───────────────────────────

    public function testCoachEstCoachEtStaffMaisPasAdmin(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_COACH));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_COACH));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_STAFF));
        self::assertSame(VoterInterface::ACCESS_DENIED,  $this->vote($token, $club, ClubVoter::CLUB_ADMIN));
    }

    public function testDirigeantEstAdmin(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_DIRIGEANT));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_ADMIN));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_STAFF));
    }

    public function testJoueurEstJoueurPasStaff(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_JOUEUR));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_JOUEUR));
        self::assertSame(VoterInterface::ACCESS_DENIED,  $this->vote($token, $club, ClubVoter::CLUB_STAFF));
    }

    public function testBenevoleN_estPasStaff(): void
    {
        $club = $this->club(1);
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_BENEVOLE));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $club, ClubVoter::CLUB_MEMBER));
        self::assertSame(VoterInterface::ACCESS_DENIED,  $this->vote($token, $club, ClubVoter::CLUB_STAFF));
    }

    // ───────────────────────── CLUB_STAFF_ELARGI ───────────────────────────

    public function testStaffElargiInclutEmployeEtTresorier(): void
    {
        $club = $this->club(1);

        $employe = $this->token($this->user(1, $club, UserClubRole::ROLE_EMPLOYE));
        $tresorier = $this->token($this->user(2, $club, UserClubRole::ROLE_TRESORIER));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($employe, $club, ClubVoter::CLUB_STAFF_ELARGI));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($tresorier, $club, ClubVoter::CLUB_STAFF_ELARGI));
    }

    public function testStaffElargiExclutBenevoleParentJoueur(): void
    {
        $club = $this->club(1);

        foreach ([UserClubRole::ROLE_BENEVOLE, UserClubRole::ROLE_PARENT, UserClubRole::ROLE_JOUEUR] as $i => $role) {
            $token = $this->token($this->user($i + 1, $club, $role));
            self::assertSame(
                VoterInterface::ACCESS_DENIED,
                $this->vote($token, $club, ClubVoter::CLUB_STAFF_ELARGI),
                sprintf('le rôle %s ne fait pas partie du staff élargi', $role)
            );
        }
    }

    // ───────────────────────── ClubAwareInterface ──────────────────────────

    public function testExtractionDuClubViaClubAwareInterface(): void
    {
        $club = $this->club(1);
        // Une entité métier (ex. Equipe/Joueur) qui appartient à ce club.
        $entite = new class($club) implements ClubAwareInterface {
            public function __construct(private Club $c) {}
            public function getClub(): ?Club { return $this->c; }
        };
        $token = $this->token($this->user(1, $club, UserClubRole::ROLE_COACH));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->vote($token, $entite, ClubVoter::CLUB_COACH),
            'le Voter doit extraire le club de l\'entité et raisonner dessus'
        );
    }

    // ─────────────── ANTI-FUITE INTER-CLUB (le test qui compte) ─────────────

    public function testCoachDuClubANeVotePasPourLeClubB(): void
    {
        $clubA = $this->club(1, 'Amiens');
        $clubB = $this->club(2, 'Tergnier');

        // L'utilisateur est COACH validé dans le club A, et RIEN dans le club B.
        $token = $this->token($this->user(1, $clubA, UserClubRole::ROLE_COACH));

        // Droit ouvert sur son club…
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($token, $clubA, ClubVoter::CLUB_COACH));
        // …mais AUCUN droit sur un autre club : c'est l'isolation multi-tenant.
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($token, $clubB, ClubVoter::CLUB_COACH));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($token, $clubB, ClubVoter::CLUB_MEMBER));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($token, $clubB, ClubVoter::CLUB_STAFF));
    }

    public function testEntiteDunAutreClubRefusee(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);

        // Entité appartenant au club B, utilisateur dirigeant du club A.
        $entiteB = new class($clubB) implements ClubAwareInterface {
            public function __construct(private Club $c) {}
            public function getClub(): ?Club { return $this->c; }
        };
        $token = $this->token($this->user(1, $clubA, UserClubRole::ROLE_DIRIGEANT));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->vote($token, $entiteB, ClubVoter::CLUB_ADMIN),
            'un dirigeant du club A ne pilote pas une entité du club B'
        );
    }
}
