<?php

namespace App\Tests\Unit\Entity\Core;

use App\Entity\Core\UserClubRole;
use PHPUnit\Framework\TestCase;

/**
 * Tests de UserClubRole — la table pivot User <-> Club <-> Rôle métier.
 *
 * On vérifie que la liste des rôles autorisés est fermée (whitelist)
 * et que setRole() rejette tout rôle inconnu, ce qui protège l'app
 * contre des données invalides en BDD.
 */
class UserClubRoleTest extends TestCase
{
    /**
     * @dataProvider provideValidRoles
     */
    public function testIsValidRoleAcceptsAllValidValues(string $role): void
    {
        $this->assertTrue(
            UserClubRole::isValidRole($role),
            "Le rôle '$role' devrait être considéré valide."
        );
    }

    public static function provideValidRoles(): array
    {
        return [
            'dirigeant'  => [UserClubRole::ROLE_DIRIGEANT],
            'coach'      => [UserClubRole::ROLE_COACH],
            'staff'      => [UserClubRole::ROLE_STAFF],
            'joueur'     => [UserClubRole::ROLE_JOUEUR],
            'parent'     => [UserClubRole::ROLE_PARENT],
            'benevole'   => [UserClubRole::ROLE_BENEVOLE],
        ];
    }

    public function testIsValidRoleRejectsUnknownRole(): void
    {
        $this->assertFalse(UserClubRole::isValidRole('SUPER_ADMIN'));
        $this->assertFalse(UserClubRole::isValidRole('admin'));
        $this->assertFalse(UserClubRole::isValidRole(''));
        $this->assertFalse(UserClubRole::isValidRole('Dirigeant'),
            'La casse compte : Dirigeant (D maj) doit être rejeté en faveur de DIRIGEANT.');
    }

    public function testSetRoleThrowsOnInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Rôle "INVALIDE" invalide/');

        $role = new UserClubRole();
        $role->setRole('INVALIDE');
    }

    public function testSetRoleAcceptsValidValue(): void
    {
        $role = new UserClubRole();
        $role->setRole(UserClubRole::ROLE_COACH);

        $this->assertSame('COACH', $role->getRole());
    }

    public function testRolesDisponiblesContainsAllConstants(): void
    {
        // Garde-fou : si on ajoute une constante ROLE_*, elle doit être dans la liste.
        $constants = (new \ReflectionClass(UserClubRole::class))->getConstants();
        $roleConstants = array_filter(
            $constants,
            fn($value, $name) => str_starts_with($name, 'ROLE_'),
            ARRAY_FILTER_USE_BOTH
        );

        foreach ($roleConstants as $name => $value) {
            $this->assertContains(
                $value,
                UserClubRole::ROLES_DISPONIBLES,
                "$name ($value) doit être listé dans ROLES_DISPONIBLES."
            );
        }
    }
}
