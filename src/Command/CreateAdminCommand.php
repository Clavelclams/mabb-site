<?php

namespace App\Command;

use App\Entity\Core\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Commande pour créer un premier compte ROLE_SUPER_ADMIN en développement.
 *
 * Usage :
 *   php bin/console app:create-admin --email=admin@mabb.fr --password=monMotDePasse
 *
 * Pourquoi cette commande existe ?
 * En prod, il n'y a pas d'inscription publique pour les admins.
 * Cette commande permet de "bootstrapper" le premier super admin
 * sans passer par la BDD manuellement.
 *
 * À N'UTILISER QU'EN DÉVELOPPEMENT ou lors de la mise en production initiale.
 */
#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée un compte ROLE_SUPER_ADMIN (premier admin ou reset).',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        // EntityManagerInterface : permet de sauvegarder l'entité en BDD
        private EntityManagerInterface $em,
        // UserPasswordHasherInterface : hashe le mot de passe proprement (bcrypt/argon2)
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'Email du compte admin (ex: admin@mabb.fr)'
            )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Mot de passe en clair (sera hashé automatiquement)'
            )
            ->addOption(
                'prenom',
                null,
                InputOption::VALUE_OPTIONAL,
                'Prénom de l\'admin',
                'Admin'
            )
            ->addOption(
                'nom',
                null,
                InputOption::VALUE_OPTIONAL,
                'Nom de l\'admin',
                'MABB'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // SymfonyStyle = helper qui formate la sortie console (titres, succès, erreurs...)
        $io = new SymfonyStyle($input, $output);

        $io->title('Création d\'un compte ROLE_SUPER_ADMIN');

        // --- Récupération et validation des options ---
        $email    = $input->getOption('email');
        $password = $input->getOption('password');
        $prenom   = $input->getOption('prenom');
        $nom      = $input->getOption('nom');

        // Si email ou password non fournis, on les demande interactivement
        if (!$email) {
            $email = $io->ask('Email de l\'admin', 'admin@mabb.fr');
        }
        if (!$password) {
            $password = $io->askHidden('Mot de passe (invisible à la saisie)');
        }

        // Validation basique de l'email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("L'email '$email' n'est pas valide.");
            return Command::FAILURE;
        }

        // Validation longueur mot de passe (sécurité minimale)
        if (strlen($password) < 8) {
            $io->error('Le mot de passe doit faire au moins 8 caractères.');
            return Command::FAILURE;
        }

        // --- Vérification si l'email existe déjà ---
        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            // L'utilisateur existe → on met à jour son rôle et son mot de passe
            $io->warning("Un compte avec l'email '$email' existe déjà. Mise à jour du mot de passe et du rôle.");
            $user = $existing;
        } else {
            // Nouvel utilisateur
            $user = new User();
            $user->setEmail($email);
            $user->setPrenom($prenom);
            $user->setNom($nom);
            $user->setRgpdConsent(true); // Admin créé manuellement = consentement implicite
            $user->setRgpdConsentAt(new \DateTimeImmutable());
        }

        // --- Hash du mot de passe ---
        // On ne stocke JAMAIS le mot de passe en clair.
        // hashPassword() applique bcrypt/argon2 selon la config password_hashers
        $hashedPassword = $this->hasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // --- Attribution du rôle ROLE_SUPER_ADMIN ---
        // setRoles() remplace tous les rôles existants par les nouveaux
        // ROLE_USER est hérité via role_hierarchy (pas besoin de l'ajouter)
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);

        // --- Persistance en BDD ---
        // persist() = "je veux sauvegarder cet objet"
        // flush()   = "exécute les requêtes SQL maintenant"
        $this->em->persist($user);
        $this->em->flush();

        // --- Résumé affiché dans le terminal ---
        $io->success("Compte admin créé avec succès !");
        $io->table(
            ['Champ', 'Valeur'],
            [
                ['Email',  $email],
                ['Prénom', $prenom],
                ['Nom',    $nom],
                ['Rôles',  implode(', ', $user->getRoles())],
                ['Actif',  $user->isActive() ? '✅ Oui' : '❌ Non'],
            ]
        );

        $io->note([
            'Connexion admin : https://mabb.fr/admin/login',
            'ATTENTION : Ne jamais utiliser cette commande pour créer des utilisateurs normaux.',
            'Les utilisateurs normaux s\'inscrivent via mabb.fr/compte/s-inscrire',
        ]);

        return Command::SUCCESS;
    }
}
