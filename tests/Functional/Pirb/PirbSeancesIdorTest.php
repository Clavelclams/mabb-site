<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pirb;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Seance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * [P0 jury CDA] Test fonctionnel d'isolation (IDOR) de l'espace PIRB.
 *
 * Contrairement aux tests unitaires du ClubVoter/TenantResolver (qui prouvent
 * la LOGIQUE d'autorisation), ce test prouve le COMPORTEMENT RÉEL de bout en
 * bout : on lance de vraies requêtes HTTP sur le firewall PIRB et on vérifie
 * qu'une joueuse ne peut pas accéder à la séance d'une autre équipe en
 * manipulant l'{id} dans l'URL (faille IDOR).
 *
 * Points techniques (défendables en jury) :
 *  - PIRB est un firewall PAR HOST (`pirb.mabb.fr`) → en test on appelle les
 *    routes avec l'hôte `pirb.localhost` pour tomber dans le bon firewall.
 *  - `loginUser()` authentifie sans passer par le formulaire (on teste
 *    l'autorisation, pas le login).
 *  - Chaque test tourne dans une TRANSACTION annulée en fin de test
 *    (`rollback`) : la base de test reste propre, aucun résidu entre tests.
 *
 * Prérequis (une seule fois) :
 *   php bin/console --env=test doctrine:database:create
 *   php bin/console --env=test doctrine:schema:create
 */
final class PirbSeancesIdorTest extends WebTestCase
{
    private const HOST = 'pirb.localhost';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Isolation : tout ce que le test écrit sera annulé en tearDown.
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    // ───────────────────────── Helpers de seed ─────────────────────────────

    private function creerClub(string $slug): Club
    {
        $club = (new Club())->setNom('Club ' . $slug)->setSlug($slug);
        $this->em->persist($club);
        return $club;
    }

    private function creerEquipe(Club $club, string $nom): Equipe
    {
        $equipe = (new Equipe())
            ->setClub($club)
            ->setNom($nom)
            ->setCategorie('U15')
            ->setSaison('2025-2026')
            ->setNiveau('Départemental')
            ->setIsActive(true);
        $this->em->persist($equipe);
        return $equipe;
    }

    private function creerUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPrenom('Compte')
            ->setNom('Test')
            ->setRoles(['ROLE_USER'])
            ->setPassword('$2y$notusedintests');
        $this->em->persist($user);
        return $user;
    }

    private function creerJoueur(Club $club, Equipe $equipe, User $user, string $prenom): Joueur
    {
        $joueur = (new Joueur())
            ->setClub($club)
            ->setEquipe($equipe)
            ->setPrenom($prenom)
            ->setNom('Test')
            ->setUser($user)
            ->setIsActive(true);
        $this->em->persist($joueur);
        return $joueur;
    }

    private function creerSeance(Club $club, Equipe $equipe): Seance
    {
        $seance = (new Seance())
            ->setClub($club)
            ->setEquipe($equipe)
            ->setDate(new \DateTimeImmutable('2026-01-15 18:00'))
            ->setLieu('Gymnase test')
            ->setType('Entrainement');
        $this->em->persist($seance);
        return $seance;
    }

    private function get(string $path): void
    {
        $this->client->request('GET', 'http://' . self::HOST . $path);
    }

    // ───────────────────────── Tests ───────────────────────────────────────

    public function testAnonymeEstRedirigeVersLogin(): void
    {
        // Sans authentification, tout l'espace PIRB est protégé (ROLE_USER).
        $this->get('/seances');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects();
    }

    public function testJoueuseVoitSaProreSeance(): void
    {
        $club = $this->creerClub('club-a');
        $equipe = $this->creerEquipe($club, 'U15 A');
        $user = $this->creerUser('joueuse.a@test.fr');
        $this->creerJoueur($club, $equipe, $user, 'Alice');
        $seance = $this->creerSeance($club, $equipe);
        $this->em->flush();

        $this->client->loginUser($user, 'pirb');
        $this->get('/seances/' . $seance->getId());

        // Sa séance à elle : accès autorisé.
        self::assertResponseIsSuccessful();
    }

    public function testJoueuseNeVoitPasLaSeanceDuneAutreEquipe(): void
    {
        // LE test IDOR : une joueuse de l'équipe A tente d'ouvrir la séance
        // de l'équipe B en devinant son {id}. Le serveur doit refuser.
        $club = $this->creerClub('club-a');
        $equipeA = $this->creerEquipe($club, 'U15 A');
        $equipeB = $this->creerEquipe($club, 'U18 B');

        $userA = $this->creerUser('joueuse.a@test.fr');
        $this->creerJoueur($club, $equipeA, $userA, 'Alice');

        // Séance qui appartient à l'équipe B (pas la sienne).
        $seanceB = $this->creerSeance($club, $equipeB);
        $this->em->flush();

        $this->client->loginUser($userA, 'pirb');
        $this->get('/seances/' . $seanceB->getId());

        // Accès refusé : la séance ne concerne pas son équipe.
        self::assertResponseStatusCodeSame(403);
    }
}
