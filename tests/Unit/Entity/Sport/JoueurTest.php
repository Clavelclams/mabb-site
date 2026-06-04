<?php

namespace App\Tests\Unit\Entity\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\ClubAwareInterface;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires sur l'entité Joueur.
 *
 * On valide notamment :
 *   - La méthode helper getNomComplet() (utilisée partout dans les vues)
 *   - L'implémentation de ClubAwareInterface
 *   - Le fait qu'un Joueur peut exister SANS User (pattern Participant VEA)
 *   - Le fait qu'un Joueur peut exister SANS Équipe (joueuse en attente d'affectation)
 */
class JoueurTest extends TestCase
{
    public function testJoueurImplementeClubAwareInterface(): void
    {
        // Multi-tenant : le ClubVoter doit pouvoir extraire le Club du Joueur.
        $this->assertInstanceOf(ClubAwareInterface::class, new Joueur());
    }

    public function testGetNomCompletRetourneLeNomComplet(): void
    {
        $joueur = new Joueur();
        $joueur->setPrenom('Léa');
        $joueur->setNom('Martin');

        // Utilisé dans tous les flash messages et titres de page.
        // Doit retourner "Prénom Nom" propre, sans espaces parasites.
        $this->assertSame('Léa Martin', $joueur->getNomComplet());
    }

    public function testGetNomCompletGereLesValeursVides(): void
    {
        $joueur = new Joueur();
        // Pas de prénom ni nom (cas d'une joueuse en cours de création)
        $this->assertSame('', $joueur->getNomComplet());

        $joueur->setPrenom('Léa');
        $joueur->setNom('');
        // Seul le prénom est rempli → on retourne "Léa" sans espace de fin
        $this->assertSame('Léa', $joueur->getNomComplet());
    }

    public function testJoueurPeutExisterSansUser(): void
    {
        $joueur = new Joueur();
        $joueur->setPrenom('Léa');
        $joueur->setNom('Martin');

        // C'est LE pattern central : une joueuse mineure n'a pas forcément
        // de compte utilisateur. Coupler Joueur et User serait une erreur
        // qu'on défend au jury (inspiré du pattern Participant VEA).
        $this->assertNull($joueur->getUser());
    }

    public function testJoueurPeutExisterSansEquipe(): void
    {
        $joueur = new Joueur();

        // Une joueuse peut être "non affectée" — par exemple en attente
        // de répartition en début de saison. L'affectation à une équipe
        // est nullable.
        $this->assertNull($joueur->getEquipe());
    }

    public function testAffectationEquipe(): void
    {
        $equipe = new Equipe();
        $equipe->setNom('U13 Féminines');
        $equipe->setCategorie('U13');

        $joueur = new Joueur();
        $joueur->setEquipe($equipe);

        $this->assertSame($equipe, $joueur->getEquipe());
    }

    public function testAffectationClub(): void
    {
        $club = new Club();
        $club->setNom('MABB');

        $joueur = new Joueur();
        $joueur->setClub($club);

        // Le multi-tenant repose sur ce getClub() :
        // ClubVoter::voteOnAttribute() appelle $joueur->getClub() pour
        // vérifier que l'user connecté a un rôle dans CE club.
        $this->assertSame($club, $joueur->getClub());
    }

    public function testNouvelleJoueuseEstActiveParDefaut(): void
    {
        $this->assertTrue((new Joueur())->isActive());
    }

    #[DataProvider('postesValidesProvider')]
    public function testTousLesPostesDefinisSontValides(string $poste): void
    {
        // La constante POSTES est utilisée dans le formulaire et dans
        // l'attribut Assert\Choice de la propriété poste. Doit rester en sync.
        $this->assertContains($poste, Joueur::POSTES);
    }

    public static function postesValidesProvider(): array
    {
        return [
            'Meneuse'     => ['Meneuse'],
            'Arrière'     => ['Arrière'],
            'Ailière'     => ['Ailière'],
            'Intérieure'  => ['Intérieure'],
            'Polyvalente' => ['Polyvalente'],
        ];
    }

    public function testNumeroMaillotPeutEtreNull(): void
    {
        $joueur = new Joueur();
        // Lors d'une création, le n° peut être inconnu — il sera attribué plus tard
        $this->assertNull($joueur->getNumeroMaillot());

        $joueur->setNumeroMaillot(7);
        $this->assertSame(7, $joueur->getNumeroMaillot());
    }
}
