<?php

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Sport\Rencontre;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires sur l'entité Rencontre (match).
 *
 * Renommé Rencontre car "match" est un mot-clé PHP 8+.
 * Tests centrés sur le workflow statut (brouillon → validé → verrouillé)
 * et les helpers métier (aResultat, isVerrouillee).
 */
class RencontreTest extends TestCase
{
    public function testStatutParDefautEstBrouillon(): void
    {
        // Toute nouvelle rencontre démarre en brouillon — un coach peut
        // ajuster les détails avant validation. C'est aussi ce qui garantit
        // qu'aucune donnée non validée ne contamine les stats.
        $this->assertSame(Rencontre::STATUT_BROUILLON, (new Rencontre())->getStatut());
    }

    public function testIsVerrouilleeReponseTrueQuandStatutVerrouille(): void
    {
        $rencontre = new Rencontre();
        $rencontre->setStatut(Rencontre::STATUT_VERROUILLE);

        // Helper utilisé dans les vues et controllers pour empêcher la
        // modification d'une feuille de match déjà verrouillée (anti-triche).
        $this->assertTrue($rencontre->isVerrouillee());
    }

    public function testIsVerrouilleeReponseFalseQuandStatutBrouillonOuValide(): void
    {
        $rencontre = new Rencontre();

        $rencontre->setStatut(Rencontre::STATUT_BROUILLON);
        $this->assertFalse($rencontre->isVerrouillee());

        $rencontre->setStatut(Rencontre::STATUT_VALIDE);
        $this->assertFalse($rencontre->isVerrouillee());
    }

    public function testAResultatReponseTrueSiLesDeuxScoresSontDefinis(): void
    {
        $rencontre = new Rencontre();
        $rencontre->setScoreEquipe(85);
        $rencontre->setScoreAdverse(72);

        // aResultat() est utilisé dans la vitrine pour afficher
        // uniquement les matchs déjà joués (score saisi).
        $this->assertTrue($rencontre->aResultat());
    }

    public function testAResultatReponseFalseSiUnDesDeuxScoresEstAbsent(): void
    {
        $rencontre = new Rencontre();

        // Aucun score → pas de résultat
        $this->assertFalse($rencontre->aResultat());

        // Score équipe seul → pas de résultat (cas suspect, à éviter)
        $rencontre->setScoreEquipe(85);
        $this->assertFalse($rencontre->aResultat());

        // Score adverse seul → pas de résultat
        $rencontre->setScoreEquipe(null);
        $rencontre->setScoreAdverse(72);
        $this->assertFalse($rencontre->aResultat());
    }

    #[DataProvider('statutsValidesProvider')]
    public function testTousLesStatutsDefinisSontValides(string $statut): void
    {
        $this->assertContains($statut, Rencontre::STATUTS);
    }

    public static function statutsValidesProvider(): array
    {
        return [
            'brouillon'  => [Rencontre::STATUT_BROUILLON],
            'valide'     => [Rencontre::STATUT_VALIDE],
            'verrouille' => [Rencontre::STATUT_VERROUILLE],
        ];
    }

    public function testDomicileEstTrueParDefaut(): void
    {
        // Choix de design : par défaut on suppose match à domicile.
        // C'est le cas le plus fréquent (en moyenne 50% des matchs à dom + le coach
        // saisit souvent les matchs depuis chez lui pour son équipe locale).
        $this->assertTrue((new Rencontre())->isDomicile());
    }
}
