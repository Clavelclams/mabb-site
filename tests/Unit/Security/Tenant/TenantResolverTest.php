<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Tenant;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use App\Security\Tenant\TenantResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [P0 jury CDA] Tests du TenantResolver — résolution du club actif.
 *
 * Le TenantResolver décide "sur quel club on travaille" pour la session
 * courante. La règle de sécurité centrale : on ne peut jamais activer un club
 * auquel on n'appartient pas, même en forgeant l'ID en session
 * (testSessionForgeeVersUnAutreClubEstIgnoree).
 *
 * Tests unitaires : session réelle en mémoire (MockArraySessionStorage),
 * Security et ClubRepository mockés. Aucune base de données.
 */
final class TenantResolverTest extends TestCase
{
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
    }

    // ───────────────────────── Helpers de montage ──────────────────────────

    private function club(int $id, bool $actif = true): Club
    {
        $club = (new Club())->setNom('Club ' . $id)->setIsActive($actif);
        return $this->setId($club, $id);
    }

    private function ucr(Club $club, string $role, bool $actif = true, string $status = UserClubRole::STATUS_ACTIVE): UserClubRole
    {
        return (new UserClubRole())
            ->setClub($club)
            ->setRole($role)
            ->setIsActive($actif)
            ->setStatus($status);
    }

    /** Utilisateur porteur d'une liste de UserClubRole. */
    private function user(UserClubRole ...$roles): User
    {
        $user = (new User())->setEmail('joueuse@test.fr');
        foreach ($roles as $r) {
            $user->addUserClubRole($r);
        }
        return $user;
    }

    private function setId(object $entity, int $id): object
    {
        // Depuis PHP 8.1, la réflexion accède aux propriétés privées sans
        // setAccessible() (devenu inutile, et déprécié en 8.5).
        $prop = new \ReflectionProperty($entity::class, 'id');
        $prop->setValue($entity, $id);
        return $entity;
    }

    /**
     * Construit un TenantResolver dont la session contient déjà les rôles
     * de l'utilisateur, avec un ClubRepository qui sait retrouver les clubs
     * fournis par leur ID.
     *
     * @param Club[] $clubsConnus clubs que le repository saura retrouver via find()
     */
    private function resolver(?User $user, array $clubsConnus = []): TenantResolver
    {
        $request = new Request();
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $map = [];
        foreach ($clubsConnus as $c) {
            $map[$c->getId()] = $c;
        }
        $repo = $this->createStub(ClubRepository::class);
        $repo->method('find')->willReturnCallback(fn ($id) => $map[$id] ?? null);

        return new TenantResolver($stack, $repo, $security);
    }

    // ───────────────────────── userBelongsToClub ───────────────────────────

    public function testAppartenanceClubValide(): void
    {
        $club = $this->club(1);
        $user = $this->user($this->ucr($club, UserClubRole::ROLE_JOUEUR));

        self::assertTrue($this->resolver($user)->userBelongsToClub($user, $club));
    }

    public function testAppartenancePendingRefusee(): void
    {
        $club = $this->club(1);
        $user = $this->user($this->ucr($club, UserClubRole::ROLE_JOUEUR, true, UserClubRole::STATUS_PENDING));

        self::assertFalse(
            $this->resolver($user)->userBelongsToClub($user, $club),
            'un rôle en attente de validation ne vaut pas appartenance'
        );
    }

    public function testAppartenanceRoleDesactiveRefusee(): void
    {
        $club = $this->club(1);
        $user = $this->user($this->ucr($club, UserClubRole::ROLE_JOUEUR, false));

        self::assertFalse($this->resolver($user)->userBelongsToClub($user, $club));
    }

    public function testN_appartientPasAUnAutreClub(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $user = $this->user($this->ucr($clubA, UserClubRole::ROLE_JOUEUR));

        self::assertFalse($this->resolver($user)->userBelongsToClub($user, $clubB));
    }

    // ───────────────────────── getUserClubs ────────────────────────────────

    public function testGetUserClubsExclutPendingEtDedoublonne(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $clubInactif = $this->club(3, actif: false);

        $user = $this->user(
            $this->ucr($clubA, UserClubRole::ROLE_JOUEUR),          // ok
            $this->ucr($clubA, UserClubRole::ROLE_BENEVOLE),        // doublon club A -> dédoublonné
            $this->ucr($clubB, UserClubRole::ROLE_COACH, true, UserClubRole::STATUS_PENDING), // pending -> exclu
            $this->ucr($clubInactif, UserClubRole::ROLE_JOUEUR),   // club inactif -> exclu
        );

        $clubs = $this->resolver($user)->getUserClubs($user);

        self::assertCount(1, $clubs, 'seul le club A actif et validé doit rester');
        self::assertSame(1, $clubs[0]->getId());
    }

    // ───────────────────────── getCurrentClub ──────────────────────────────

    public function testAucunClubSansUtilisateur(): void
    {
        self::assertNull($this->resolver(null)->getCurrentClub());
    }

    public function testAutoSelectionQuandUnSeulClub(): void
    {
        $club = $this->club(1);
        $user = $this->user($this->ucr($club, UserClubRole::ROLE_JOUEUR));

        $club_resolu = $this->resolver($user, [$club])->getCurrentClub();

        self::assertNotNull($club_resolu);
        self::assertSame(1, $club_resolu->getId());
        // L'auto-sélection persiste le choix en session.
        self::assertSame(1, $this->session->get('active_club_id'));
    }

    public function testPlusieursClubsImposeUnChoix(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $user = $this->user(
            $this->ucr($clubA, UserClubRole::ROLE_JOUEUR),
            $this->ucr($clubB, UserClubRole::ROLE_COACH),
        );

        self::assertNull(
            $this->resolver($user, [$clubA, $clubB])->getCurrentClub(),
            'avec plusieurs clubs, l\'utilisateur doit choisir (null tant qu\'aucun choix)'
        );
    }

    public function testClubEnSessionValideEstUtilise(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $user = $this->user(
            $this->ucr($clubA, UserClubRole::ROLE_JOUEUR),
            $this->ucr($clubB, UserClubRole::ROLE_COACH),
        );
        // L'utilisateur a explicitement choisi le club B.
        $this->session->set('active_club_id', 2);

        $resolu = $this->resolver($user, [$clubA, $clubB])->getCurrentClub();

        self::assertSame(2, $resolu?->getId());
    }

    // ─────────────── SÉCURITÉ : session forgée ignorée ──────────────────────

    public function testSessionForgeeVersUnAutreClubEstIgnoree(): void
    {
        // L'utilisateur n'appartient QU'au club A, mais un active_club_id=2
        // (club B) traîne en session (résidu, ou tentative de manipulation).
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $user = $this->user($this->ucr($clubA, UserClubRole::ROLE_JOUEUR));
        $this->session->set('active_club_id', 2);

        $resolu = $this->resolver($user, [$clubA, $clubB])->getCurrentClub();

        // Le club B forgé est rejeté ; comme l'utilisateur n'a qu'un club,
        // on retombe sur le club A (auto-sélection), jamais sur le club B.
        self::assertSame(1, $resolu?->getId(), 'un club forgé en session ne doit jamais être servi');
        self::assertSame(1, $this->session->get('active_club_id'), 'la session est corrigée vers le vrai club');
    }

    // ───────────────────────── setCurrentClub ──────────────────────────────

    public function testSetCurrentClubRefuseUnClubNonAffilie(): void
    {
        $clubA = $this->club(1);
        $clubB = $this->club(2);
        $user = $this->user($this->ucr($clubA, UserClubRole::ROLE_JOUEUR));

        $this->expectException(\LogicException::class);
        $this->resolver($user, [$clubA, $clubB])->setCurrentClub($clubB);
    }

    public function testSetCurrentClubAccepteUnClubAffilie(): void
    {
        $clubA = $this->club(1);
        $user = $this->user($this->ucr($clubA, UserClubRole::ROLE_JOUEUR));

        $this->resolver($user, [$clubA])->setCurrentClub($clubA);

        self::assertSame(1, $this->session->get('active_club_id'));
    }

    // ───────────────────────── rôles du club actif ─────────────────────────

    public function testHasPendingMembership(): void
    {
        $club = $this->club(1);
        $user = $this->user($this->ucr($club, UserClubRole::ROLE_JOUEUR, true, UserClubRole::STATUS_PENDING));

        self::assertTrue($this->resolver($user)->hasPendingMembership($user));
    }

    public function testGetCurrentUserRolesEtHasRole(): void
    {
        $club = $this->club(1);
        $user = $this->user(
            $this->ucr($club, UserClubRole::ROLE_COACH),
            $this->ucr($club, UserClubRole::ROLE_BENEVOLE),
        );
        $resolver = $this->resolver($user, [$club]);

        $roles = $resolver->getCurrentUserRoles();
        sort($roles);
        self::assertSame([UserClubRole::ROLE_BENEVOLE, UserClubRole::ROLE_COACH], $roles);
        self::assertTrue($resolver->hasRole(UserClubRole::ROLE_COACH));
        self::assertFalse($resolver->hasRole(UserClubRole::ROLE_DIRIGEANT));
    }
}
