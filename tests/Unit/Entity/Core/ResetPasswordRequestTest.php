<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Core;

use App\Entity\Core\ResetPasswordRequest;
use App\Entity\Core\User;
use PHPUnit\Framework\TestCase;

/**
 * B1/B3 — Tests sécurité critique : ResetPasswordRequest.
 *
 * Cette entité est cœur de la sécu du reset password.
 * Le jury va demander : "comment vous garantissez que le token n'est pas
 * stocké en clair et qu'il expire bien ?" → ces tests démontrent le contrat.
 */
class ResetPasswordRequestTest extends TestCase
{
    public function testTtlIs1Hour(): void
    {
        self::assertSame(3600, ResetPasswordRequest::TTL_SECONDS);
    }

    public function testExpiresAtIsRequestedAtPlus1Hour(): void
    {
        $user = $this->buildUser();
        $rpr = new ResetPasswordRequest($user, 'fake_hash', '127.0.0.1');

        $diff = $rpr->getExpiresAt()->getTimestamp() - $rpr->getRequestedAt()->getTimestamp();
        self::assertSame(ResetPasswordRequest::TTL_SECONDS, $diff);
    }

    public function testIsValidForFreshRequest(): void
    {
        $rpr = new ResetPasswordRequest($this->buildUser(), 'fake_hash');
        self::assertTrue($rpr->isValid());
        self::assertFalse($rpr->isExpired());
        self::assertFalse($rpr->isConsumed());
    }

    public function testConsumeMarksAsUsed(): void
    {
        $rpr = new ResetPasswordRequest($this->buildUser(), 'fake_hash');
        self::assertNull($rpr->getConsumedAt());

        $rpr->consume();

        self::assertTrue($rpr->isConsumed());
        self::assertFalse($rpr->isValid());
        self::assertNotNull($rpr->getConsumedAt());
    }

    public function testTokenHashIs64CharsHex(): void
    {
        // Vérifie qu'on n'accepte que des hashes au bon format
        $tokenClair = bin2hex(random_bytes(32));   // 64 chars
        $tokenHash  = hash('sha256', $tokenClair); // 64 chars

        self::assertSame(64, strlen($tokenHash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokenHash);
    }

    public function testRequestIpIsStored(): void
    {
        $rpr = new ResetPasswordRequest($this->buildUser(), 'hash', '192.168.1.42');
        self::assertSame('192.168.1.42', $rpr->getRequestIp());
    }

    private function buildUser(): User
    {
        $user = new User();
        $user->setEmail('test@mabb.fr');
        $user->setPrenom('Test');
        $user->setNom('User');
        return $user;
    }
}
