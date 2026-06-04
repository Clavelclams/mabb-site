<?php

namespace App\DataFixtures;

use App\Entity\Core\Club;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Fixtures Phase 2 (Sport) : données de démo pour le bloc Effectif.
 *
 * Installe sous le club MABB (créé par AppFixtures) :
 *   - 4 équipes : U13F, U15F, U18F, Séniors F
 *   - 18 joueuses fictives (prénoms inventés, dates de naissance cohérentes
 *     avec la catégorie)
 *
 * Dépend de AppFixtures pour récupérer le Club MABB.
 *
 * Commande : php bin/console doctrine:fixtures:load
 *   ⚠️  Écrase toutes les données existantes (purge complète).
 *   Ajouter --append pour conserver l'existant.
 */
class SportFixtures extends Fixture implements DependentFixtureInterface
{
    /**
     * Déclare que cette fixture doit s'exécuter APRÈS AppFixtures.
     * Symfony fait un tri topologique pour respecter cet ordre.
     */
    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // =====================================================================
        // Récupération du Club MABB créé par AppFixtures
        // =====================================================================
        $club = $manager->getRepository(Club::class)
            ->findOneBy(['slug' => 'mabb']);

        if (!$club) {
            throw new \RuntimeException(
                'Club "mabb" introuvable. As-tu bien chargé AppFixtures avant ?'
            );
        }

        // =====================================================================
        // Création des équipes
        // =====================================================================
        $equipesData = [
            ['nom' => 'U13 Féminines',    'categorie' => 'U13',      'niveau' => 'Départemental'],
            ['nom' => 'U15 Féminines',    'categorie' => 'U15',      'niveau' => 'Régional'],
            ['nom' => 'U18 Féminines',    'categorie' => 'U18',      'niveau' => 'Régional'],
            ['nom' => 'Séniors Féminines', 'categorie' => 'Senior F', 'niveau' => 'Pré-National'],
        ];

        $equipes = [];
        foreach ($equipesData as $data) {
            $equipe = new Equipe();
            $equipe->setClub($club);
            $equipe->setNom($data['nom']);
            $equipe->setCategorie($data['categorie']);
            $equipe->setSaison('2025-2026');
            $equipe->setNiveau($data['niveau']);
            $equipe->setIsActive(true);
            $manager->persist($equipe);
            $equipes[$data['categorie']] = $equipe;
        }

        // =====================================================================
        // Création des joueuses
        // Tableau structuré : [équipe_catégorie => [joueuses]]
        // =====================================================================
        $joueusesData = [
            // U13 = nées 2013-2014 (âge 11-12)
            'U13' => [
                ['Léa',     'Martin',   '2013-05-12', 'Meneuse',    4],
                ['Camille', 'Dubois',   '2013-09-03', 'Ailière',    7],
                ['Sara',    'Bensaid',  '2014-02-20', 'Intérieure', 10],
                ['Inès',    'Lefebvre', '2013-11-08', 'Polyvalente', 12],
            ],
            // U15 = nées 2011-2012 (âge 13-14)
            'U15' => [
                ['Marie', 'Lambert',  '2011-03-15', 'Meneuse',     5],
                ['Chloé', 'Bertrand', '2012-07-22', 'Arrière',     8],
                ['Awa',   'Diallo',   '2011-12-05', 'Ailière',     11],
                ['Manon', 'Roux',     '2012-04-18', 'Intérieure',  14],
                ['Élise', 'Mercier',  '2011-09-30', 'Polyvalente', 15],
            ],
            // U18 = nées 2008-2010 (âge 15-17)
            'U18' => [
                ['Anaïs', 'Petit',  '2008-06-10', 'Meneuse',     6],
                ['Léna',  'Garcia', '2009-03-25', 'Arrière',     9],
                ['Fatou', 'Camara', '2008-11-12', 'Ailière',     13],
                ['Julia', 'Moreau', '2009-08-19', 'Intérieure',  23],
                ['Sofia', 'Henry',  '2010-01-05', 'Polyvalente', 33],
            ],
            // Séniors F = adultes (nées avant 2008)
            'Senior F' => [
                ['Émilie',  'Robert',    '1995-04-22', 'Meneuse',    1],
                ['Sabrina', 'Faure',     '1998-11-14', 'Arrière',    16],
                ['Yasmine', 'Boukhari',  '2000-07-08', 'Ailière',    21],
                ['Laura',   'Schmidt',   '1996-02-28', 'Intérieure', 44],
            ],
        ];

        $countJoueuses = 0;
        foreach ($joueusesData as $categorie => $joueuses) {
            $equipe = $equipes[$categorie];

            foreach ($joueuses as $idx => $j) {
                $joueur = new Joueur();
                $joueur->setClub($club);
                $joueur->setEquipe($equipe);
                $joueur->setPrenom($j[0]);
                $joueur->setNom($j[1]);
                $joueur->setDateNaissance(new \DateTimeImmutable($j[2]));
                $joueur->setPoste($j[3]);
                $joueur->setNumeroMaillot($j[4]);
                $joueur->setIsActive(true);
                // Pas de licence FFBB ni de compte User dans les fixtures
                // (ce sera saisi à la main par le coach via le formulaire)
                $manager->persist($joueur);
                $countJoueuses++;
            }
        }

        $manager->flush();

        echo "\n✅ Fixtures Sport chargées :\n";
        echo "   Équipes  : " . count($equipes) . " (U13F, U15F, U18F, Séniors F)\n";
        echo "   Joueuses : {$countJoueuses} (réparties dans les 4 équipes)\n";
        echo "   Saison   : 2025-2026\n\n";
    }
}
