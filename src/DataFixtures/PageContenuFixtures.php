<?php

namespace App\DataFixtures;

use App\Entity\Vitrine\PageContenu;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PageContenuFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $pages = [
            [
                'slug'      => 'accueil',
                'nom'       => 'Accueil',
                'sousTitre' => 'Bienvenue à la MABB — Amiens Métropole Basket Ball',
                'contenu'   => '',
            ],
            [
                'slug'      => 'club',
                'nom'       => 'Le Club',
                'sousTitre' => 'MABB — Un club de basket ancré dans les quartiers d\'Amiens',
                'contenu'   => '',
            ],
            [
                'slug'      => 'equipes',
                'nom'       => 'Nos Équipes',
                'sousTitre' => 'Du Mini Basket aux Seniors, la MABB forme à tous les niveaux',
                'contenu'   => '',
            ],
            [
                'slug'      => 'equipes-3x3',
                'nom'       => 'Équipe 3x3',
                'sousTitre' => 'Nos coachs sur le terrain — format exclusif, 100% MABB',
                'contenu'   => '',
            ],
            [
                'slug'      => 'membres',
                'nom'       => 'Membres',
                'sousTitre' => 'Les membres de la MABB — adhérents et licenciés',
                'contenu'   => '',
            ],
            [
                'slug'      => 'formation',
                'nom'       => 'Formation & Parcours Inclusifs',
                'sousTitre' => 'La MABB, une deuxième chance pour chacun',
                'contenu'   => '<h2>Le club comme levier d\'insertion</h2><p>Au-delà du sport, la MABB accompagne des personnes en difficulté vers la qualification, la formation et l\'emploi.</p>',
            ],
            [
                'slug'      => 'cite-educative',
                'nom'       => 'Cité Éducative',
                'sousTitre' => 'MABB partenaire des établissements scolaires d\'Amiens Métropole',
                'contenu'   => '',
            ],
            [
                'slug'      => 'projet-sport-etude',
                'nom'       => 'Projet Sport-Études',
                'sousTitre' => 'La MABB et la Cité Scolaire d\'Amiens — construire l\'excellence ensemble',
                'contenu'   => '<h2>Un projet ambitieux</h2><p>La MABB ambitionne de créer une <strong>section sport-études</strong> en partenariat avec la Cité Scolaire d\'Amiens.</p><p>L\'objectif : permettre aux jeunes joueuses de mener de front excellence sportive et réussite scolaire.</p>',
            ],
            [
                'slug'      => 'numerique',
                'nom'       => 'Espace Numérique',
                'sousTitre' => 'La transformation digitale du club',
                'contenu'   => '<h2>MABB Manager &amp; PIRB</h2><p>La MABB développe ses propres outils numériques pour gérer le club et accompagner les joueuses dans leur progression.</p>',
            ],
            [
                'slug'      => 'calendrier',
                'nom'       => 'Calendrier',
                'sousTitre' => 'Matchs, entraînements et événements MABB',
                'contenu'   => '',
            ],
            [
                'slug'      => 'galerie',
                'nom'       => 'Galerie',
                'sousTitre' => 'Les photos et vidéos du club',
                'contenu'   => '',
            ],
            [
                'slug'      => 'nos-reseaux',
                'nom'       => 'Nos Réseaux',
                'sousTitre' => 'Suivez la MABB sur les réseaux sociaux',
                'contenu'   => '',
            ],
            [
                'slug'      => 'contact',
                'nom'       => 'Contact',
                'sousTitre' => 'Contactez la MABB — Amiens Métropole Basket Ball',
                'contenu'   => '',
            ],
        ];

        foreach ($pages as $data) {
            // Éviter les doublons si la fixture est relancée
            $existing = $manager->getRepository(PageContenu::class)->findOneBy(['pageSlug' => $data['slug']]);
            if ($existing) {
                continue;
            }

            $page = new PageContenu();
            $page->setPageSlug($data['slug']);
            $page->setPageNom($data['nom']);
            $page->setSousTitre($data['sousTitre']);
            $page->setContenu($data['contenu']);
            $manager->persist($page);
        }

        $manager->flush();
    }
}
