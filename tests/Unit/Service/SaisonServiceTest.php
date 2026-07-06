<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\SaisonService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * [V2.4 06/07/2026] Tests du service saison (source de vérité unique).
 *
 * NB : getSaisonCourante() dépend de la date système — on teste donc des
 * INVARIANTS (format, ordre, bornes) et les méthodes déterministes, pas
 * une valeur figée qui casserait chaque année.
 */
final class SaisonServiceTest extends TestCase
{
    private SaisonService $service;
    private Session $session;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $stack = new RequestStack();
        $stack->push($request);
        $this->service = new SaisonService($stack);
    }

    // ── Navigation entre saisons (déterministe) ──────────────────────────

    public function testSaisonSuivante(): void
    {
        self::assertSame('2026-2027', $this->service->getSaisonSuivante('2025-2026'));
    }

    public function testSaisonPrecedente(): void
    {
        self::assertSame('2024-2025', $this->service->getSaisonPrecedente('2025-2026'));
    }

    // ── Saisons disponibles : invariants ─────────────────────────────────

    public function testSaisonsDisponiblesFormatEtOrdre(): void
    {
        $saisons = $this->service->getSaisonsDisponibles();

        self::assertNotEmpty($saisons);
        foreach ($saisons as $s) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $s);
            [$debut, $fin] = explode('-', $s);
            self::assertSame((int) $debut + 1, (int) $fin, 'saison = 2 années consécutives');
        }
        // Ordre décroissant (plus récente d'abord)
        $triees = $saisons;
        rsort($triees);
        self::assertSame($triees, $saisons);
    }

    public function testAucuneSaisonFutureProposee(): void
    {
        // [06/07/2026] Demande explicite : impossible de sélectionner une
        // saison qui n'a pas commencé. La plus récente proposée DOIT être
        // la saison courante calculée par la date.
        $saisons = $this->service->getSaisonsDisponibles();
        self::assertSame($this->service->getSaisonCourante(), $saisons[0]);
    }

    public function testSaisonCouranteEstValide(): void
    {
        self::assertTrue($this->service->isValide($this->service->getSaisonCourante()));
    }

    public function testSaisonFantaisisteInvalide(): void
    {
        self::assertFalse($this->service->isValide('2099-2100'));
        self::assertFalse($this->service->isValide('n-importe-quoi'));
    }

    // ── Saison active : session vs calcul ────────────────────────────────

    public function testSaisonActiveParDefautEstLaCourante(): void
    {
        self::assertSame(
            $this->service->getSaisonCourante(),
            $this->service->getSaisonActive(),
            'sans choix manuel, la saison active = saison calculée (bascule auto)'
        );
    }

    public function testChoixManuelValideRespecte(): void
    {
        $precedente = $this->service->getSaisonPrecedente($this->service->getSaisonCourante());
        $this->session->set('active_saison', $precedente);

        self::assertSame($precedente, $this->service->getSaisonActive());
    }

    public function testChoixManuelInvalideRetombeSurLaCourante(): void
    {
        // Un résidu de session invalide (saison future, valeur corrompue)
        // ne doit JAMAIS être servi : on retombe sur la saison calculée.
        $this->session->set('active_saison', '2099-2100');

        self::assertSame($this->service->getSaisonCourante(), $this->service->getSaisonActive());
    }
}
