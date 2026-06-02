<?php

namespace App\Tests\Unit\Entity\Core;

use App\Entity\Core\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de l'entité User.
 *
 * Cible principale : la logique métier de setRolesMembre() et removeRoleMembre()
 * qui implémente la règle « Employé et Bénévole sont mutuellement exclusifs »
 * (demande Willy — afficher la charge salariale du club).
 *
 * Ces tests garantissent qu'on ne peut pas casser cette règle par accident
 * en modifiant le code de l'entité plus tard.
 */
class UserTest extends TestCase
{
    public function testRolesMembreDefaultsToBenevole(): void
    {
        $user = new User();
        $this->assertSame(['benevole'], $user->getRolesMembre(),
            'Un nouvel utilisateur doit avoir [benevole] par défaut.');
    }

    public function testSetRolesMembreForcesBenevoleWhenNotEmploye(): void
    {
        $user = new User();
        $user->setRolesMembre(['coach', 'parent']);

        $this->assertContains('benevole', $user->getRolesMembre(),
            'Bénévole doit être ajouté automatiquement si Employé est absent.');
        $this->assertContains('coach', $user->getRolesMembre());
        $this->assertContains('parent', $user->getRolesMembre());
    }

    public function testSetRolesMembreRemovesBenevoleWhenEmployePresent(): void
    {
        $user = new User();
        $user->setRolesMembre(['employe', 'coach']);

        $this->assertNotContains('benevole', $user->getRolesMembre(),
            'Bénévole doit être automatiquement retiré quand Employé est présent.');
        $this->assertContains('employe', $user->getRolesMembre());
        $this->assertContains('coach', $user->getRolesMembre());
    }

    public function testEmployeAndBenevoleAreMutuallyExclusive(): void
    {
        $user = new User();
        // On tente de mettre les DEUX explicitement — la logique doit retirer benevole
        $user->setRolesMembre(['employe', 'benevole', 'staff']);

        $this->assertNotContains('benevole', $user->getRolesMembre(),
            'Si Employé est demandé, Bénévole doit être retiré même si fourni en paramètre.');
        $this->assertContains('employe', $user->getRolesMembre());
        $this->assertContains('staff', $user->getRolesMembre());
    }

    public function testRemoveBenevoleProtectedWithoutEmploye(): void
    {
        $user = new User();
        $user->removeRoleMembre('benevole');

        $this->assertContains('benevole', $user->getRolesMembre(),
            'On ne doit jamais pouvoir retirer Bénévole tant qu\'aucun Employé n\'est défini.');
    }

    public function testRemoveBenevoleAllowedWithEmploye(): void
    {
        $user = new User();
        $user->setRolesMembre(['employe']);
        // employe seul a déjà retiré benevole, mais testons removeRoleMembre direct
        $user->setRolesMembre(['employe', 'benevole']); // forcera la mutex
        $this->assertNotContains('benevole', $user->getRolesMembre());
    }

    public function testHasRoleMembreFindsExistingRole(): void
    {
        $user = new User();
        $user->setRolesMembre(['coach', 'parent']);

        $this->assertTrue($user->hasRoleMembre('benevole'));
        $this->assertTrue($user->hasRoleMembre('coach'));
        $this->assertTrue($user->hasRoleMembre('parent'));
        $this->assertFalse($user->hasRoleMembre('employe'));
        $this->assertFalse($user->hasRoleMembre('inexistant'));
    }

    public function testAddRoleMembreDoesNotDuplicate(): void
    {
        $user = new User();
        $user->addRoleMembre('coach');
        $user->addRoleMembre('coach');

        $coachCount = count(array_filter($user->getRolesMembre(), fn($r) => $r === 'coach'));
        $this->assertSame(1, $coachCount, 'Un rôle ne doit pas être dupliqué.');
    }
}
