<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pirb;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;

/**
 * [P0 jury CDA] IDOR sur la lecture des stats d'un match (GET /stats/match/{id}).
 *
 * Enjeu RGPD : une joueuse ne doit voir la fiche stats d'un match QUE si elle
 * appartient à l'équipe concernée. Ici on prouve qu'en devinant l'{id} d'un
 * match d'une autre équipe, elle est refusée (403).
 *
 * Route sans CSRF (GET) → test direct, sans jeton.
 */
final class PirbStatsMatchIdorTest extends PirbIdorTestCase
{
    private function creerRencontre(Club $club, Equipe $equipe): Rencontre
    {
        $rencontre = (new Rencontre())
            ->setClub($club)
            ->setEquipe($equipe)
            ->setAdversaire('Adversaire Test')
            ->setDate(new \DateTimeImmutable('2026-01-20 20:00'));
        $this->em->persist($rencontre);
        return $rencontre;
    }

    public function testJoueuseNeVoitPasLeMatchDuneAutreEquipe(): void
    {
        $club = $this->creerClub('club-a');
        $equipeA = $this->creerEquipe($club, 'U15 A');
        $equipeB = $this->creerEquipe($club, 'U18 B');

        $userA = $this->creerUser('joueuse.a@test.fr');
        $this->creerJoueur($club, $equipeA, $userA, 'Alice');

        // Match de l'équipe B, pas la sienne.
        $rencontreB = $this->creerRencontre($club, $equipeB);
        $this->em->flush();

        $this->client->loginUser($userA, 'pirb');
        $this->requete('GET', '/stats/match/' . $rencontreB->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
