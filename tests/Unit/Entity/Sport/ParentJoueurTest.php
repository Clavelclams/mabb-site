<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\ParentJoueur;
use PHPUnit\Framework\TestCase;

/**
 * B3 — Test PIRB V1.4c+d : workflow validation parent-enfant.
 *
 * Critique pour sécurité : un parent ne peut PAS être lié à un enfant
 * sans validation explicite du staff. Cette entité encode ce workflow.
 */
class ParentJoueurTest extends TestCase
{
    public function testNewParentJoueurIsPending(): void
    {
        $pj = new ParentJoueur();
        self::assertTrue($pj->isPending());
        self::assertFalse($pj->isActive());
        self::assertFalse($pj->isRejected());
        self::assertSame(ParentJoueur::STATUT_PENDING, $pj->getStatut());
    }

    public function testActivationTracksValidator(): void
    {
        $pj = new ParentJoueur();
        $validator = $this->buildUser('staff@mabb.fr');

        $pj->setStatut(ParentJoueur::STATUT_ACTIVE);
        $pj->setValidePar($validator);
        $pj->setValideAt(new \DateTimeImmutable());

        self::assertTrue($pj->isActive());
        self::assertSame($validator, $pj->getValidePar());
        self::assertNotNull($pj->getValideAt());
    }

    public function testRejectionPossible(): void
    {
        $pj = new ParentJoueur();
        $pj->setStatut(ParentJoueur::STATUT_REJECTED);

        self::assertTrue($pj->isRejected());
        self::assertFalse($pj->isActive());
        self::assertFalse($pj->isPending());
    }

    public function testDemandeParConstants(): void
    {
        // Workflow : qui a initié la demande change l'UI/validation
        self::assertSame('parent', ParentJoueur::DEMANDE_PAR_PARENT);
        self::assertSame('staff', ParentJoueur::DEMANDE_PAR_STAFF);
        self::assertSame('joueur', ParentJoueur::DEMANDE_PAR_JOUEUR);
    }

    public function testParentAndJoueurLinkage(): void
    {
        $parent = $this->buildUser('parent@mabb.fr');
        $joueur = $this->buildJoueur();

        $pj = new ParentJoueur();
        $pj->setParentUser($parent);
        $pj->setJoueur($joueur);
        $pj->setDemandePar(ParentJoueur::DEMANDE_PAR_PARENT);

        self::assertSame($parent, $pj->getParentUser());
        self::assertSame($joueur, $pj->getJoueur());
        self::assertSame(ParentJoueur::DEMANDE_PAR_PARENT, $pj->getDemandePar());
    }

    private function buildUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setPrenom('Test');
        $u->setNom('User');
        return $u;
    }

    private function buildJoueur(): Joueur
    {
        $j = new Joueur();
        $j->setPrenom('Sarah');
        $j->setNom('Test');
        return $j;
    }
}
