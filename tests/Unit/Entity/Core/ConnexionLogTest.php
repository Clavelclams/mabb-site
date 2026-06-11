<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Core;

use App\Entity\Core\ConnexionLog;
use App\Entity\Core\User;
use PHPUnit\Framework\TestCase;

/**
 * B2/B3 — Tests ConnexionLog : factory methods + sécurité (truncate UA).
 */
class ConnexionLogTest extends TestCase
{
    public function testFactorySuccesSetsAllFields(): void
    {
        $user = $this->buildUser();
        $log = ConnexionLog::succes($user, '127.0.0.1', 'Mozilla/5.0', ConnexionLog::CONTEXTE_MANAGER);

        self::assertTrue($log->isSucces());
        self::assertSame($user, $log->getUser());
        self::assertSame('test@mabb.fr', $log->getEmailTente());
        self::assertSame('127.0.0.1', $log->getIp());
        self::assertSame(ConnexionLog::CONTEXTE_MANAGER, $log->getContexte());
        self::assertNotNull($log->getCreatedAt());
        self::assertNull($log->getRaisonEchec());
    }

    public function testFactoryEchecSetsRaison(): void
    {
        $log = ConnexionLog::echec(
            'bad@email.com',
            null,
            '127.0.0.1',
            'Mozilla/5.0',
            ConnexionLog::ECHEC_MOTDEPASSE,
            ConnexionLog::CONTEXTE_MANAGER,
        );

        self::assertFalse($log->isSucces());
        self::assertNull($log->getUser());
        self::assertSame(ConnexionLog::ECHEC_MOTDEPASSE, $log->getRaisonEchec());
    }

    public function testUserAgentTruncatedAt500Chars(): void
    {
        $hugeUa = str_repeat('A', 2000);
        $log = ConnexionLog::succes($this->buildUser(), '127.0.0.1', $hugeUa, ConnexionLog::CONTEXTE_MANAGER);

        // Sécurité : pas d'overflow BDD si UA volumineux (PostgreSQL/MySQL VARCHAR(500))
        self::assertLessThanOrEqual(500, mb_strlen($log->getUserAgent()));
    }

    public function testNullUserAgentStaysNull(): void
    {
        $log = ConnexionLog::succes($this->buildUser(), '127.0.0.1', null, ConnexionLog::CONTEXTE_MANAGER);
        self::assertNull($log->getUserAgent());
    }

    public function testContextesConstants(): void
    {
        // Garantit qu'on ne change pas par erreur les constantes utilisées par le LoginLogListener
        self::assertSame('manager', ConnexionLog::CONTEXTE_MANAGER);
        self::assertSame('pirb', ConnexionLog::CONTEXTE_PIRB);
        self::assertSame('admin', ConnexionLog::CONTEXTE_ADMIN);
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
