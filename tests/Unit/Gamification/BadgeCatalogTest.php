<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gamification;

use App\Gamification\BadgeCatalog;
use PHPUnit\Framework\TestCase;

/**
 * B3 — Test BadgeCatalog : sanity check sur le catalogue + axes.
 *
 * Le catalogue est la source de vérité pour PIRB (épingler badges) +
 * Manager (afficher badges débloqués). Toute régression dans les clés
 * casse les liens user.
 */
class BadgeCatalogTest extends TestCase
{
    public function testCatalogIsNotEmpty(): void
    {
        $all = BadgeCatalog::all();
        self::assertGreaterThanOrEqual(20, count($all), 'Le catalogue doit avoir au moins 20 badges');
    }

    public function testEachBadgeHasRequiredKeys(): void
    {
        foreach (BadgeCatalog::all() as $key => $badge) {
            self::assertIsString($key, "Badge key must be a string");
            self::assertArrayHasKey('nom', $badge, "Badge $key missing 'nom'");
            self::assertArrayHasKey('axe', $badge, "Badge $key missing 'axe'");
            self::assertArrayHasKey('description', $badge, "Badge $key missing 'description'");
            self::assertArrayHasKey('icone', $badge, "Badge $key missing 'icone'");
            self::assertNotEmpty($badge['nom'], "Badge $key has empty nom");
        }
    }

    public function testAxesValidPourChaqueBadge(): void
    {
        $axesValides = [
            BadgeCatalog::AXE_REGULARITE,
            BadgeCatalog::AXE_PERFORMANCE,
            BadgeCatalog::AXE_BENEVOLAT,
            BadgeCatalog::AXE_EMPLOYE,
            BadgeCatalog::AXE_TRANSVERSE,
        ];
        foreach (BadgeCatalog::all() as $key => $badge) {
            self::assertContains(
                $badge['axe'],
                $axesValides,
                "Badge $key a un axe invalide : {$badge['axe']}"
            );
        }
    }

    public function testB18BadgesPerformanceBasketExistent(): void
    {
        // B18 a ajouté 10 badges Axe B (performance basket)
        $codesB = BadgeCatalog::codesParAxe(BadgeCatalog::AXE_PERFORMANCE);
        self::assertGreaterThanOrEqual(
            10,
            count($codesB),
            'Doit avoir au moins 10 badges Axe B (Performance basket) après B18'
        );
    }

    public function testSpectateurBadgesExistAfterPirbV15(): void
    {
        // 5 badges spectateur ajoutés en PIRB V1.5 (Mission Spectateur)
        $all = BadgeCatalog::all();
        $spectateur = array_filter($all, fn(string $k) => str_starts_with($k, 'C_SPECTATEUR'), ARRAY_FILTER_USE_KEY);

        self::assertCount(5, $spectateur, 'Doit y avoir exactement 5 badges Spectateur (Axe C)');
    }
}
