<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Sport\Rencontre;
use PHPUnit\Framework\TestCase;

/**
 * [V2.3 06/07/2026] Tests de la composition A/B du match interne.
 *
 * Invariant MÉTIER critique : une joueuse est dans l'équipe A OU B,
 * JAMAIS les deux (garanti par setCompositionInterne, A prioritaire).
 */
final class RencontreCompositionInterneTest extends TestCase
{
    private function rencontreInterne(): Rencontre
    {
        return (new Rencontre())->setTypeRencontre(Rencontre::TYPE_ENTRAINEMENT_INTERNE);
    }

    // ── Exclusivité A/B ──────────────────────────────────────────────────

    public function testUneJoueuseNePeutPasEtreDansLesDeuxEquipes(): void
    {
        $r = $this->rencontreInterne();
        // La joueuse 3 est demandée dans A ET B → A prioritaire, retirée de B
        $r->setCompositionInterne([1, 2, 3], [3, 4, 5]);

        self::assertSame([1, 2, 3], $r->getEquipeAIds());
        self::assertSame([4, 5], $r->getEquipeBIds());
        self::assertSame('A', $r->coteJoueur(3));
    }

    public function testDedoublonnageEtCastInt(): void
    {
        $r = $this->rencontreInterne();
        $r->setCompositionInterne(['2', 2, '1'], ['9', 9]);

        self::assertSame([1, 2], $r->getEquipeAIds());
        self::assertSame([9], $r->getEquipeBIds());
    }

    // ── Activation du mode deux équipes ──────────────────────────────────

    public function testModeActifSeulementAvecJoueusesDesDeuxCotes(): void
    {
        $r = $this->rencontreInterne();
        self::assertFalse($r->isInterneDeuxEquipes(), 'pas de composition = mode classique');

        $r->setCompositionInterne([1, 2], []);
        self::assertFalse($r->isInterneDeuxEquipes(), 'équipe B vide = mode classique');

        $r->setCompositionInterne([1, 2], [3]);
        self::assertTrue($r->isInterneDeuxEquipes());
    }

    public function testUnMatchOfficielNestJamaisEnModeDeuxEquipes(): void
    {
        // Requalifier la rencontre en OFFICIEL doit désactiver le mode A/B
        // SANS perdre la composition (donnée conservée, juste inerte).
        $r = $this->rencontreInterne();
        $r->setCompositionInterne([1], [2]);
        self::assertTrue($r->isInterneDeuxEquipes());

        $r->setTypeRencontre(Rencontre::TYPE_OFFICIEL);
        self::assertFalse($r->isInterneDeuxEquipes());
        self::assertNotNull($r->getCompositionInterne(), 'la composition n\'est pas détruite');
    }

    public function testCompositionVideRepasseEnModeClassique(): void
    {
        $r = $this->rencontreInterne();
        $r->setCompositionInterne([1], [2]);
        $r->setCompositionInterne([], []);

        self::assertNull($r->getCompositionInterne());
        self::assertFalse($r->isInterneDeuxEquipes());
    }

    // ── Helpers de consultation ──────────────────────────────────────────

    public function testCoteEtAppartenance(): void
    {
        $r = $this->rencontreInterne();
        $r->setCompositionInterne([10], [20]);

        self::assertSame('A', $r->coteJoueur(10));
        self::assertSame('B', $r->coteJoueur(20));
        self::assertNull($r->coteJoueur(999), 'hors composition');
        self::assertTrue($r->estDansComposition(10));
        self::assertFalse($r->estDansComposition(999));
    }

    public function testNomsPersonnalisesAvecDefauts(): void
    {
        $r = $this->rencontreInterne();
        $r->setCompositionInterne([1], [2], 'Les Bleues', '');

        self::assertSame('Les Bleues', $r->getEquipeANom());
        self::assertSame('Équipe B', $r->getEquipeBNom(), 'nom vide → défaut');
    }
}
