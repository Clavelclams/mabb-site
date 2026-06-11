<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Core;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use PHPUnit\Framework\TestCase;

/**
 * B2/B3 — Tests RgpdRequest : workflow effacement/refus + traçabilité.
 */
class RgpdRequestTest extends TestCase
{
    public function testNewRequestIsPending(): void
    {
        $req = new RgpdRequest($this->buildUser(), RgpdRequest::TYPE_EFFACEMENT);

        self::assertTrue($req->isPending());
        self::assertSame(RgpdRequest::STATUT_PENDING, $req->getStatut());
        self::assertNull($req->getTraiteeAt());
        self::assertNull($req->getTraiteePar());
    }

    public function testValiderSetsAdminAndDate(): void
    {
        $req = new RgpdRequest($this->buildUser());
        $admin = $this->buildUser('admin@mabb.fr');

        $req->valider($admin);

        self::assertSame(RgpdRequest::STATUT_VALIDEE, $req->getStatut());
        self::assertSame($admin, $req->getTraiteePar());
        self::assertNotNull($req->getTraiteeAt());
        self::assertFalse($req->isPending());
    }

    public function testRefuserExigeMotif(): void
    {
        $req = new RgpdRequest($this->buildUser());
        $admin = $this->buildUser('admin@mabb.fr');

        $req->refuser($admin, 'Litige comptable en cours');

        self::assertSame(RgpdRequest::STATUT_REFUSEE, $req->getStatut());
        self::assertSame('Litige comptable en cours', $req->getMotifAdmin());
    }

    public function testMarquerEffectueeApresValidation(): void
    {
        $req = new RgpdRequest($this->buildUser());
        $req->valider($this->buildUser('admin@mabb.fr'));
        $req->marquerEffectuee();

        self::assertSame(RgpdRequest::STATUT_EFFECTUEE, $req->getStatut());
    }

    public function testStatutsConstants(): void
    {
        self::assertSame('pending', RgpdRequest::STATUT_PENDING);
        self::assertSame('validee', RgpdRequest::STATUT_VALIDEE);
        self::assertSame('effectuee', RgpdRequest::STATUT_EFFECTUEE);
        self::assertSame('refusee', RgpdRequest::STATUT_REFUSEE);
    }

    private function buildUser(string $email = 'user@mabb.fr'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setPrenom('U');
        $u->setNom('U');
        return $u;
    }
}
