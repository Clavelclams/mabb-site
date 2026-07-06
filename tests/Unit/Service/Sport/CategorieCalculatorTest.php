<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sport;

use App\Entity\Sport\Joueur;
use App\Service\Sport\CategorieCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * [V2.4 06/07/2026] Tests du calcul de catégorie FFBB par date de naissance.
 *
 * Règle testée : âge de référence = année de FIN de saison − année de
 * naissance → tranche fédérale (U7/U9/U11/U13/U15/U18/Senior).
 * C'est la brique du passage de saison automatique (app:passage-saison).
 */
final class CategorieCalculatorTest extends TestCase
{
    private CategorieCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CategorieCalculator();
    }

    private function joueuseNee(string $date): Joueur
    {
        $j = new Joueur();
        $j->setDateNaissance(new \DateTimeImmutable($date));
        return $j;
    }

    // ── Âge de référence ─────────────────────────────────────────────────

    public function testAgeReferenceUtiliseAnneeDeFinDeSaison(): void
    {
        $j = $this->joueuseNee('2013-05-10');
        // Saison 2026-2027 → fin 2027 → 2027 − 2013 = 14
        self::assertSame(14, $this->calculator->ageReference($j, '2026-2027'));
    }

    public function testAgeReferenceSansDateDeNaissance(): void
    {
        self::assertNull($this->calculator->ageReference(new Joueur(), '2026-2027'));
    }

    // ── Catégorie ────────────────────────────────────────────────────────

    #[DataProvider('provideCategories')]
    public function testCategorieParAnneeDeNaissance(string $naissance, string $saison, string $attendue): void
    {
        $j = $this->joueuseNee($naissance);
        self::assertSame($attendue, $this->calculator->categorie($j, $saison));
    }

    public static function provideCategories(): iterable
    {
        // Le cas central du besoin exprimé : U10 l'an dernier → U11 maintenant
        yield 'U11 → passe U13 la saison suivante (borne)' => ['2016-03-01', '2026-2027', 'U11']; // 2027−2016 = 11
        yield 'même joueuse saison suivante'                => ['2016-03-01', '2027-2028', 'U13']; // 12 → U13
        yield 'U15 (âge 14)'    => ['2013-05-10', '2026-2027', 'U15'];
        yield 'U15 (âge 15, borne haute)' => ['2012-01-01', '2026-2027', 'U15'];
        yield 'U18 (âge 16)'    => ['2011-12-31', '2026-2027', 'U18'];
        yield 'U18 (âge 18, borne haute)' => ['2009-06-15', '2026-2027', 'U18'];
        yield 'Senior (âge 19)' => ['2008-06-15', '2026-2027', 'Senior'];
        yield 'U7 (âge 4)'      => ['2023-01-01', '2026-2027', 'U7'];
    }

    public function testCategorieSansDateDeNaissance(): void
    {
        self::assertNull($this->calculator->categorie(new Joueur(), '2026-2027'));
    }

    // ── Compatibilité équipe ─────────────────────────────────────────────

    public function testJouerAuDessusDeSonAgeEstCompatible(): void
    {
        $u13 = $this->joueuseNee('2015-01-01'); // âge 12 en 2026-2027 → U13
        self::assertTrue($this->calculator->estCompatible($u13, 'U15', '2026-2027'), 'surclassement autorisé');
        self::assertTrue($this->calculator->estCompatible($u13, 'U13', '2026-2027'), 'sa catégorie');
    }

    public function testJouerEnDessousDeSonAgeEstIncompatible(): void
    {
        $u15 = $this->joueuseNee('2013-01-01'); // âge 14
        self::assertFalse($this->calculator->estCompatible($u15, 'U13', '2026-2027'));
    }

    public function testCategoriesClubMonoAnnee(): void
    {
        // Le club aligne des U14/U16/U17 : compatibilité par âge exact
        $agee14 = $this->joueuseNee('2013-01-01');
        self::assertTrue($this->calculator->estCompatible($agee14, 'U14', '2026-2027'));
        self::assertFalse($this->calculator->estCompatible($this->joueuseNee('2012-01-01'), 'U14', '2026-2027')); // âge 15
    }

    public function testSansDateDeNaissanceOnNeBloqueJamais(): void
    {
        // Choix assumé : l'incertitude ne bloque pas, le coach tranche
        self::assertTrue($this->calculator->estCompatible(new Joueur(), 'U13', '2026-2027'));
    }

    // ── Surclassement ────────────────────────────────────────────────────

    public function testDetectionSurclassement(): void
    {
        $u13 = $this->joueuseNee('2015-01-01'); // U13 en 2026-2027
        self::assertTrue($this->calculator->estSurclassement($u13, 'U15', '2026-2027'));
        self::assertFalse($this->calculator->estSurclassement($u13, 'U13', '2026-2027'));
    }

    public function testMineureEnSeniorEstUnSurclassement(): void
    {
        $u18 = $this->joueuseNee('2010-01-01'); // âge 17
        self::assertTrue($this->calculator->estSurclassement($u18, 'Senior F', '2026-2027'));
    }
}
