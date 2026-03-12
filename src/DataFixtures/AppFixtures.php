<?php

namespace App\DataFixtures;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures Phase 1 : données de base pour démarrer.
 *
 * Installe :
 * - 1 club MABB
 * - 1 super admin (toi)
 * - 1 utilisateur test par rôle métier (pour tester les accès)
 *
 * Commande : php bin/console doctrine:fixtures:load
 * ⚠️  Écrase toutes les données existantes.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // =====================================================================
        // 1. Création du club MABB
        // =====================================================================
        $club = new Club();
        $club->setNom('Amiens Métropole Basket-Ball');
        $club->setSlug('mabb');
        $club->setVille('Amiens');
        $club->setCodePostal('80000');
        $club->setIsActive(true);
        $manager->persist($club);

        // =====================================================================
        // 2. Création des utilisateurs + rôles
        // =====================================================================
        $usersData = [
            [
                'prenom'   => 'Moussa',
                'nom'      => 'Clavel',
                'email'    => 'admin@mabb.fr',
                'password' => 'Admin1234!',
                'roles'    => ['ROLE_SUPER_ADMIN'],  // Rôle Symfony
                'metaRole' => UserClubRole::ROLE_DIRIGEANT,
            ],
            [
                'prenom'   => 'Thomas',
                'nom'      => 'Coach',
                'email'    => 'coach@mabb.fr',
                'password' => 'Coach1234!',
                'roles'    => [],
                'metaRole' => UserClubRole::ROLE_COACH,
            ],
            [
                'prenom'   => 'Lucas',
                'nom'      => 'Joueur',
                'email'    => 'joueur@mabb.fr',
                'password' => 'Joueur1234!',
                'roles'    => [],
                'metaRole' => UserClubRole::ROLE_JOUEUR,
            ],
            [
                'prenom'   => 'Marie',
                'nom'      => 'Parent',
                'email'    => 'parent@mabb.fr',
                'password' => 'Parent1234!',
                'roles'    => [],
                'metaRole' => UserClubRole::ROLE_PARENT,
            ],
            [
                'prenom'   => 'Julie',
                'nom'      => 'Bénévole',
                'email'    => 'benevole@mabb.fr',
                'password' => 'Benevole1234!',
                'roles'    => [],
                'metaRole' => UserClubRole::ROLE_BENEVOLE,
            ],
        ];

        foreach ($usersData as $data) {
            $user = new User();
            $user->setPrenom($data['prenom']);
            $user->setNom($data['nom']);
            $user->setEmail($data['email']);
            $user->setPassword(
                $this->passwordHasher->hashPassword($user, $data['password'])
            );
            $user->setRoles($data['roles']);
            $user->setRgpdConsent(true);
            $user->setIsActive(true);
            $manager->persist($user);

            // Lier l'utilisateur au club avec son rôle métier
            $ucr = new UserClubRole();
            $ucr->setUser($user);
            $ucr->setClub($club);
            $ucr->setRole($data['metaRole']);
            $ucr->setIsActive(true);
            $manager->persist($ucr);
        }

        $manager->flush();

        echo "\n✅ Fixtures chargées :\n";
        echo "   Club  : Amiens Métropole Basket-Ball (slug: mabb)\n";
        echo "   Users : admin@mabb.fr | coach@mabb.fr | joueur@mabb.fr | parent@mabb.fr | benevole@mabb.fr\n";
        echo "   MDP   : Admin1234! | Coach1234! | Joueur1234! | Parent1234! | Benevole1234!\n\n";
    }
}
