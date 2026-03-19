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
                'slug'      => 'projet-sport-etude',
                'nom'       => 'Projet Sport-Études',
                'sousTitre' => 'La MABB et la Cité Scolaire d\'Amiens — construire l\'excellence ensemble',
                'contenu'   => "## Un projet ambitieux\n\nLa MABB ambitionne de créer une **section sport-études** en partenariat avec la Cité Scolaire d'Amiens.\n\nL'objectif : permettre aux jeunes joueuses de mener de front excellence sportive et réussite scolaire.",
            ],
            [
                'slug'      => 'formation',
                'nom'       => 'Formation & Parcours Inclusifs',
                'sousTitre' => 'La MABB, une deuxième chance pour chacun',
                'contenu'   => "## Le club comme levier d'insertion\n\nAu-delà du sport, la MABB accompagne des personnes en difficulté vers la qualification, la formation et l'emploi.",
            ],
            [
                'slug'      => 'numerique',
                'nom'       => 'Espace Numérique',
                'sousTitre' => 'La transformation digitale du club',
                'contenu'   => "## MABB Manager & PIRB\n\nLa MABB développe ses propres outils numériques pour gérer le club et accompagner les joueuses dans leur progression.",
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
