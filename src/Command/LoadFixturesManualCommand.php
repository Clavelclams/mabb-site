<?php

namespace App\Command;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Commande temporaire : charge les fixtures Phase 1 sans DoctrineFixturesBundle.
 * Supprimer après installation du bundle (composer require doctrine/doctrine-fixtures-bundle --dev).
 */
#[AsCommand(name: 'app:load-fixtures-manual', description: 'Charge les fixtures Phase 1 (temporaire)')]
class LoadFixturesManualCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $club = new Club();
        $club->setNom('Amiens Métropole Basket-Ball');
        $club->setSlug('mabb');
        $club->setVille('Amiens');
        $club->setCodePostal('80000');
        $club->setIsActive(true);
        $this->em->persist($club);

        $usersData = [
            ['prenom' => 'Moussa', 'nom' => 'Clavel',   'email' => 'admin@mabb.fr',    'password' => 'Admin1234!',    'roles' => ['ROLE_SUPER_ADMIN'], 'metaRole' => UserClubRole::ROLE_DIRIGEANT],
            ['prenom' => 'Thomas', 'nom' => 'Coach',     'email' => 'coach@mabb.fr',    'password' => 'Coach1234!',    'roles' => [],                   'metaRole' => UserClubRole::ROLE_COACH],
            ['prenom' => 'Lucas',  'nom' => 'Joueur',    'email' => 'joueur@mabb.fr',   'password' => 'Joueur1234!',   'roles' => [],                   'metaRole' => UserClubRole::ROLE_JOUEUR],
            ['prenom' => 'Marie',  'nom' => 'Parent',    'email' => 'parent@mabb.fr',   'password' => 'Parent1234!',   'roles' => [],                   'metaRole' => UserClubRole::ROLE_PARENT],
            ['prenom' => 'Julie',  'nom' => 'Bénévole',  'email' => 'benevole@mabb.fr', 'password' => 'Benevole1234!', 'roles' => [],                   'metaRole' => UserClubRole::ROLE_BENEVOLE],
        ];

        foreach ($usersData as $data) {
            $user = new User();
            $user->setPrenom($data['prenom']);
            $user->setNom($data['nom']);
            $user->setEmail($data['email']);
            $user->setPassword($this->hasher->hashPassword($user, $data['password']));
            $user->setRoles($data['roles']);
            $user->setRgpdConsent(true);
            $user->setIsActive(true);
            $this->em->persist($user);

            $ucr = new UserClubRole();
            $ucr->setUser($user);
            $ucr->setClub($club);
            $ucr->setRole($data['metaRole']);
            $ucr->setIsActive(true);
            $this->em->persist($ucr);
        }

        $this->em->flush();

        $output->writeln('');
        $output->writeln('<info>✅ Fixtures chargées :</info>');
        $output->writeln('   Club  : Amiens Métropole Basket-Ball (slug: mabb)');
        $output->writeln('   Users : admin@mabb.fr | coach@mabb.fr | joueur@mabb.fr | parent@mabb.fr | benevole@mabb.fr');
        $output->writeln('   MDP   : Admin1234! | Coach1234! | Joueur1234! | Parent1234! | Benevole1234!');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
