<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pirb;

use App\Entity\Core\Club;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\SeanceTir;

/**
 * [P0 jury CDA] Protections de la route destructive
 * DELETE /shot-chart/{id}/supprimer.
 *
 * Note honnête sur le périmètre : valider un jeton CSRF stocké en session
 * depuis un test fonctionnel se heurte au SameOriginCsrfTokenManager de
 * Symfony 7 (le jeton doit préexister dans la session du client). On ne teste
 * donc pas ici le franchissement complet jusqu'au contrôle de propriété.
 *
 * Ce que ces tests prouvent quand même, et qui compte pour une route qui
 * SUPPRIME des données :
 *   1. la suppression exige une authentification (firewall PIRB) ;
 *   2. la suppression est protégée par un jeton CSRF (anti-CSRF actif).
 *
 * Le contrôle de PROPRIÉTÉ de cette route (seance.joueur === joueur connecté)
 * est, lui, couvert par :
 *   - les tests unitaires ClubVoter/TenantResolver (logique d'autorisation) ;
 *   - les tests fonctionnels IDOR séances + stats/match (même patron
 *     d'isolation, sans CSRF) ;
 *   - l'audit 21_AUDIT_ISOLATION_PIRB_2026-07-07 (lecture du contrôle inline).
 */
final class PirbShotChartIdorTest extends PirbIdorTestCase
{
    private function creerSeanceTir(Club $club, Joueur $joueur): SeanceTir
    {
        $seance = (new SeanceTir())
            ->setClub($club)
            ->setJoueur($joueur)
            ->setSource(SeanceTir::SOURCE_ENTRAINEMENT)
            ->setDateSeance(new \DateTimeImmutable('2026-01-10'));
        $this->em->persist($seance);
        return $seance;
    }

    public function testSuppressionExigeUneAuthentification(): void
    {
        // Anonyme : le firewall PIRB doit rediriger vers le login AVANT
        // d'atteindre le contrôleur (peu importe que la séance existe).
        $this->requete('DELETE', '/shot-chart/999999/supprimer');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects();
    }

    public function testSuppressionEstProtegeeParCsrf(): void
    {
        $club = $this->creerClub('club-a');
        $equipe = $this->creerEquipe($club, 'U15 A');
        $user = $this->creerUser('joueuse.a@test.fr');
        $joueur = $this->creerJoueur($club, $equipe, $user, 'Alice');
        $seance = $this->creerSeanceTir($club, $joueur);
        $this->em->flush();

        // Connectée, propriétaire de la séance, MAIS sans jeton CSRF valide.
        // La route destructive doit refuser (403 « Token invalide »).
        $this->client->loginUser($user, 'pirb');
        $this->requete('DELETE', '/shot-chart/' . $seance->getId() . '/supprimer');

        self::assertResponseStatusCodeSame(403);
    }
}
