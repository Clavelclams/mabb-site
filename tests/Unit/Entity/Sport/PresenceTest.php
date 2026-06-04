<?php

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Sport\Joueur;
use App\Entity\Sport\Presence;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\Seance;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Validation;

/**
 * Tests unitaires sur l'entité Presence.
 *
 * Test central : la contrainte XOR (exclusif) — une Presence DOIT cibler
 * soit une Séance soit une Rencontre, mais pas les deux à la fois et pas
 * aucune des deux. C'est la méthode Callback isExactlyOneTargetSet()
 * qui le garantit, et ce test vérifie qu'elle marche.
 *
 * Pourquoi pas deux tables séparées (PresenceSeance / PresenceRencontre) ?
 * Voir docblock de l'entité — choix de modélisation justifié.
 */
class PresenceTest extends TestCase
{
    public function testPresenceValideAvecUneSeanceUniquement(): void
    {
        $presence = new Presence();
        $presence->setJoueur(new Joueur());
        $presence->setSeance(new Seance());
        // PAS de rencontre — c'est exclusif

        $violations = $this->validate($presence);

        // Aucune violation sur le callback XOR
        $this->assertEmpty(
            $this->filterCallbackViolations($violations),
            'Une Presence avec uniquement une Séance doit être valide.'
        );
    }

    public function testPresenceValideAvecUneRencontreUniquement(): void
    {
        $presence = new Presence();
        $presence->setJoueur(new Joueur());
        $presence->setRencontre(new Rencontre());
        // PAS de séance — c'est exclusif

        $violations = $this->validate($presence);

        $this->assertEmpty(
            $this->filterCallbackViolations($violations),
            'Une Presence avec uniquement une Rencontre doit être valide.'
        );
    }

    public function testPresenceInvalideAvecAucuneCible(): void
    {
        $presence = new Presence();
        $presence->setJoueur(new Joueur());
        // Ni séance ni rencontre — invalide

        $violations = $this->validate($presence);
        $callbackViolations = $this->filterCallbackViolations($violations);

        $this->assertNotEmpty(
            $callbackViolations,
            'Une Presence sans cible (ni séance ni rencontre) doit être rejetée.'
        );
    }

    public function testPresenceInvalideAvecLesDeuxCibles(): void
    {
        $presence = new Presence();
        $presence->setJoueur(new Joueur());
        $presence->setSeance(new Seance());
        $presence->setRencontre(new Rencontre());
        // Les deux à la fois — invalide

        $violations = $this->validate($presence);
        $callbackViolations = $this->filterCallbackViolations($violations);

        $this->assertNotEmpty(
            $callbackViolations,
            'Une Presence avec à la fois une Séance ET une Rencontre doit être rejetée.'
        );
    }

    public function testPresenceParDefautEstMarqueePresente(): void
    {
        // Le pointage par défaut considère la joueuse présente.
        // Le coach corrige seulement les absences — ergonomie : on coche
        // moins de cases en moyenne.
        $this->assertTrue((new Presence())->isPresent());
    }

    public function testSourceParDefautEstManuel(): void
    {
        // Pointage par défaut = manuel (coach coche la liste).
        // Le scan QR est une seconde option qui change explicitement la source.
        // Permet de distinguer en analytics : qui scan vs qui est pointé à la main.
        $this->assertSame(Presence::SOURCE_MANUEL, (new Presence())->getSource());
    }

    // ============================================================
    // Helpers privés
    // ============================================================

    /**
     * Lance le validateur Symfony et retourne les violations détectées.
     */
    private function validate(Presence $presence): array
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return iterator_to_array($validator->validate($presence));
    }

    /**
     * Filtre uniquement les violations qui proviennent du Callback XOR
     * (et pas d'autres asserts éventuels). Ça rend les assertions plus précises.
     *
     * @param ConstraintViolation[] $violations
     */
    private function filterCallbackViolations(array $violations): array
    {
        return array_filter(
            $violations,
            fn($v) => str_contains(
                $v->getMessage(),
                'Une présence doit cibler une Séance OU une Rencontre'
            )
        );
    }
}
