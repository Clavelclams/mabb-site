<?php

declare(strict_types=1);

namespace App\Tests\Functional\Manager;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Tests\Functional\Pirb\PirbIdorTestCase;

/**
 * [BUG-01 + P0 jury] Régression du 500 sur /joueuses/{id}/missions/nouvelle
 * ET contrôle d'autorisation de la route (host manager).
 *
 * Le 500 historique (BUG-01) venait du recalcul XP/badges après création de
 * mission ; corrigé par B-205 (try/catch). Ce test verrouille le fix : le
 * formulaire s'affiche sans 500 pour un staff légitime.
 *
 * Il prouve aussi l'isolation : un staff d'un AUTRE club ne peut pas ouvrir le
 * formulaire de mission d'un joueur qui n'est pas dans son club (403 via
 * ClubVoter::CLUB_STAFF).
 *
 * Réutilise le seed + l'isolation transactionnelle de PirbIdorTestCase (base
 * fonctionnelle générique), mais tape sur le host `manager.localhost`.
 */
final class MissionAccessTest extends PirbIdorTestCase
{
    private function staffDeClub(string $email, Club $club): User
    {
        $user = $this->creerUser($email);
        $ucr = (new UserClubRole())
            ->setClub($club)
            ->setRole(UserClubRole::ROLE_DIRIGEANT)
            ->setIsActive(true)
            ->setStatus(UserClubRole::STATUS_ACTIVE);
        $user->addUserClubRole($ucr); // lie le user au rôle
        $this->em->persist($ucr);
        return $user;
    }

    /** Joueur cible de la mission (pas besoin de compte User lié). */
    private function joueurCible(Club $club, Equipe $equipe): Joueur
    {
        $joueur = (new Joueur())
            ->setClub($club)
            ->setEquipe($equipe)
            ->setPrenom('Cible')
            ->setNom('Test')
            ->setIsActive(true);
        $this->em->persist($joueur);
        return $joueur;
    }

    private function getManager(string $path): void
    {
        $this->client->request('GET', 'http://manager.localhost' . $path);
    }

    public function testStaffDuClubVoitLeFormulaireMission(): void
    {
        $club = $this->creerClub('club-a');
        $equipe = $this->creerEquipe($club, 'U15 A');
        $joueur = $this->joueurCible($club, $equipe);
        $staff = $this->staffDeClub('staff.a@test.fr', $club);
        $this->em->flush();

        $this->client->loginUser($staff, 'manager');
        $this->getManager('/joueuses/' . $joueur->getId() . '/missions/nouvelle');

        // Régression BUG-01 : le formulaire s'affiche, plus aucun 500.
        self::assertResponseIsSuccessful();
    }

    public function testStaffDunAutreClubEstRefuse(): void
    {
        $clubA = $this->creerClub('club-a');
        $equipeA = $this->creerEquipe($clubA, 'U15 A');
        $joueur = $this->joueurCible($clubA, $equipeA);

        $clubB = $this->creerClub('club-b');
        $staffB = $this->staffDeClub('staff.b@test.fr', $clubB);
        $this->em->flush();

        // Staff du club B tente d'ouvrir le formulaire d'un joueur du club A.
        $this->client->loginUser($staffB, 'manager');
        $this->getManager('/joueuses/' . $joueur->getId() . '/missions/nouvelle');

        self::assertResponseStatusCodeSame(403);
    }
}
