<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pirb;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * [P0 jury CDA] Classe de base des tests fonctionnels d'isolation PIRB.
 *
 * Mutualise ce qui est commun à tous les tests IDOR de l'espace PIRB :
 *  - le client HTTP de test + l'EntityManager ;
 *  - l'isolation par transaction annulée en fin de test (base propre) ;
 *  - les helpers de seed (Club, Équipe, User, Joueur) calqués sur SportFixtures ;
 *  - l'appel HTTP sur le bon hôte (firewall PIRB par host `pirb.localhost`).
 *
 * Chaque test concret (séances, shot-chart, stats...) n'ajoute que le seed
 * spécifique à son entité et ses assertions.
 */
abstract class PirbIdorTestCase extends WebTestCase
{
    protected const HOST = 'pirb.localhost';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
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

    protected function creerClub(string $slug): Club
    {
        $club = (new Club())->setNom('Club ' . $slug)->setSlug($slug);
        $this->em->persist($club);
        return $club;
    }

    protected function creerEquipe(Club $club, string $nom): Equipe
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

    protected function creerUser(string $email): User
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

    protected function creerJoueur(Club $club, Equipe $equipe, User $user, string $prenom): Joueur
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

    // ───────────────────────── Utilitaires HTTP ────────────────────────────

    protected function requete(string $method, string $path, array $server = []): void
    {
        $this->client->request($method, 'http://' . self::HOST . $path, [], [], $server);
    }

    /** Jeton CSRF valide pour l'intention donnée (ex. shot_chart_supprimer_12). */
    protected function csrfToken(string $intention): string
    {
        return static::getContainer()
            ->get('security.csrf.token_manager')
            ->getToken($intention)
            ->getValue();
    }
}
