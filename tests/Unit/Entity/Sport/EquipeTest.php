<?php

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Sport\Equipe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires sur l'entité Equipe.
 *
 * On teste UNIQUEMENT la logique métier de l'entité (sans BDD) :
 *   - Les getters/setters
 *   - L'implémentation de ClubAwareInterface
 *   - La structure des collections (joueurs, séances, rencontres)
 *
 * On NE teste PAS ici la validation Assert (champs requis, format saison...)
 * — ce sont des tests d'intégration qui nécessitent le ValidatorComponent.
 */
class EquipeTest extends TestCase
{
    public function testEquipeImplementeClubAwareInterface(): void
    {
        // Une Equipe doit être ClubAware pour que le ClubVoter puisse l'utiliser
        // sans avoir à connaître le type Equipe précisément.
        // C'est l'Open/Closed Principle qu'on défend au jury.
        $equipe = new Equipe();
        $this->assertInstanceOf(ClubAwareInterface::class, $equipe);
    }

    public function testGetClubRetourneLeClubAffecte(): void
    {
        $club = new Club();
        $club->setNom('MABB');

        $equipe = new Equipe();
        $equipe->setClub($club);

        // L'entité doit retourner le club qu'on lui a affecté.
        // C'est la base du multi-tenant : le Voter appelle getClub()
        // pour savoir à qui appartient l'entité.
        $this->assertSame($club, $equipe->getClub());
    }

    public function testNouvelleEquipeEstActiveParDefaut(): void
    {
        $equipe = new Equipe();

        // Une nouvelle équipe est active par défaut (champ ?bool initialisé à true
        // dans la propriété). On ne veut pas qu'un coach crée une équipe archivée
        // par accident.
        $this->assertTrue($equipe->isActive());
    }

    public function testArchiveUneEquipe(): void
    {
        $equipe = new Equipe();
        $equipe->setIsActive(false);

        // Archivage = setIsActive(false). On ne supprime jamais une équipe
        // car les joueuses, matchs et bilans y sont rattachés (historique sportif).
        $this->assertFalse($equipe->isActive());
    }

    public function testCollectionsSontInitialiseesVides(): void
    {
        $equipe = new Equipe();

        // Le constructeur doit initialiser les collections Doctrine vides.
        // Sinon, le premier add() sur null planterait avec "Call to a member
        // function add() on null".
        $this->assertCount(0, $equipe->getJoueurs());
        $this->assertCount(0, $equipe->getSeances());
        $this->assertCount(0, $equipe->getRencontres());
    }

    #[DataProvider('categoriesValidesProvider')]
    public function testToutesLesCategoriesDefiniesSontValides(string $categorie): void
    {
        // La constante CATEGORIES doit contenir toutes les catégories
        // qu'on autorise dans le formulaire. Toute modification de cette liste
        // doit passer ce test.
        $this->assertContains($categorie, Equipe::CATEGORIES);
    }

    public static function categoriesValidesProvider(): array
    {
        return [
            'U7'           => ['U7'],
            'U13'          => ['U13'],
            'U18'          => ['U18'],
            'Senior F'     => ['Senior F'],
            'Senior H'     => ['Senior H'],
            'Loisir Mixte' => ['Loisir Mixte'],
        ];
    }
}
