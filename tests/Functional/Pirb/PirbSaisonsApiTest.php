<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pirb;

use App\Entity\Core\ApiToken;
use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * [B4 lot 2, 07/07/2026] Tests fonctionnels du sélecteur de saison de l'API PIRB.
 *
 * Couvre l'endpoint /api/pirb/saisons et le paramètre ?saison= ajouté à
 * /api/pirb/stats/saison. Premier test HTTP de l'API Bearer (jusqu'ici la
 * couche API n'avait aucun test fonctionnel — lacune signalée à l'audit).
 *
 * Isolation : transaction annulée en fin de test (même schéma que
 * PirbIdorTestCase). Auth : jeton opaque forgé via ApiToken::creerPour().
 */
final class PirbSaisonsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

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

    /**
     * Seed minimal + jeton Bearer en clair.
     *
     * @return string le jeton EN CLAIR à envoyer dans l'en-tête Authorization
     */
    private function seedJoueurAvecToken(): string
    {
        $club = (new Club())->setNom('Club API')->setSlug('club-api');
        $this->em->persist($club);

        $equipe = (new Equipe())
            ->setClub($club)
            ->setNom('U15 API')
            ->setCategorie('U15')
            ->setSaison('2025-2026')
            ->setNiveau('Départemental')
            ->setIsActive(true);
        $this->em->persist($equipe);

        $user = (new User())
            ->setEmail('joueuse.api@test.local')
            ->setPrenom('Joueuse')
            ->setNom('API')
            ->setRoles(['ROLE_USER'])
            ->setPassword('$2y$notusedintests');
        $this->em->persist($user);

        $joueur = (new Joueur())
            ->setClub($club)
            ->setEquipe($equipe)
            ->setPrenom('Joueuse')
            ->setNom('API')
            ->setUser($user)
            ->setIsActive(true);
        $this->em->persist($joueur);

        [$token, $clair] = ApiToken::creerPour($user, 'PHPUnit');
        $this->em->persist($token);

        $this->em->flush();

        return $clair;
    }

    /** @return array<string,string> en-tête Authorization Bearer */
    private function authHeader(string $clair): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $clair];
    }

    // ── /api/pirb/saisons ────────────────────────────────────────────────

    public function testSaisonsExigeAuthentification(): void
    {
        // Firewall api stateless + access_control IS_AUTHENTICATED_FULLY :
        // sans jeton, on doit être rejeté (401), jamais 200.
        $this->client->request('GET', '/api/pirb/saisons');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testSaisonsRenvoieCouranteEtListe(): void
    {
        $clair = $this->seedJoueurAvecToken();

        $this->client->request('GET', '/api/pirb/saisons', [], [], $this->authHeader($clair));

        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('courante', $data);
        self::assertArrayHasKey('saisons', $data);
        self::assertIsArray($data['saisons']);
        self::assertNotEmpty($data['saisons']);

        // La saison courante est en tête de liste (aucune saison future).
        self::assertSame($data['courante'], $data['saisons'][0]);
        // Format YYYY-YYYY sur chaque entrée.
        foreach ($data['saisons'] as $s) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $s);
        }
    }

    // ── /api/pirb/stats/saison?saison= ───────────────────────────────────

    public function testStatsSaisonRejetteSaisonFuture(): void
    {
        $clair = $this->seedJoueurAvecToken();

        // 2099-2100 n'existe pas dans getSaisonsDisponibles() → 400.
        $this->client->request(
            'GET',
            '/api/pirb/stats/saison?saison=2099-2100',
            [],
            [],
            $this->authHeader($clair)
        );

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testStatsSaisonAccepteSaisonValideEtEchoLeLibelle(): void
    {
        $clair = $this->seedJoueurAvecToken();

        // Une saison passée connue et forcément dans la liste disponible.
        $saison = '2024-2025';
        $this->client->request(
            'GET',
            '/api/pirb/stats/saison?saison=' . $saison,
            [],
            [],
            $this->authHeader($clair)
        );

        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getContent(), true);
        self::assertIsArray($data);
        // Le champ saison renvoyé reflète bien la saison demandée.
        self::assertSame($saison, $data['saison'] ?? null);
    }

    public function testStatsSaisonSansParamUtiliseLaCourante(): void
    {
        $clair = $this->seedJoueurAvecToken();

        $this->client->request('GET', '/api/pirb/stats/saison', [], [], $this->authHeader($clair));

        $res = $this->client->getResponse();
        self::assertSame(200, $res->getStatusCode());

        $data = json_decode((string) $res->getContent(), true);
        // Rétrocompatibilité : sans ?saison=, on sert la saison courante.
        self::assertArrayHasKey('saison', $data);
        self::assertMatchesRegularExpression('/^\d{4}-\d{4}$/', (string) $data['saison']);
    }
}
