<?php

namespace App\DataFixtures;

use App\Entity\Vitrine\Article;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ArticleFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $articles = [
            [
                'titre'   => 'Saison 2025-2026 : objectif Pré-Nationale',
                'contenu' => '<p>La MABB vise la Pré-Nationale pour les U13, U15 et U18 cette saison. Les Seniors se stabilisent en régional avec une équipe reconstituée et motivée.</p><p>Après un été de recrutement intense, le staff technique a renforcé toutes les catégories. Les entraînements ont repris mi-septembre avec un effectif complet.</p>',
                'statut'  => Article::STATUT_PUBLIE,
                'date'    => new \DateTimeImmutable('2025-07-15'),
            ],
            [
                'titre'   => '4 titres en Coupe de la Somme — un quadruplé historique',
                'contenu' => '<p>U11, U13, U15 et U18 remportent la Coupe de la Somme lors d\'une journée mémorable. Un quadruplé historique pour le club.</p><p>Jamais dans l\'histoire de la MABB un tel palmarès n\'avait été réalisé en une seule édition. Félicitations à toutes les joueuses et aux éducateurs.</p>',
                'statut'  => Article::STATUT_PUBLIE,
                'date'    => new \DateTimeImmutable('2025-05-20'),
            ],
            [
                'titre'   => 'Christina Kpadan sélectionnée en Équipe de France U15',
                'contenu' => '<p>Notre joueuse U15 Christina Kpadan est retenue pour le projet de performance fédéral de la FFBB. Une fierté pour tout le club.</p><p>Christina, formée à la MABB depuis l\'âge de 9 ans, rejoint le groupe France pour un stage de détection nationale. Elle représente le meilleur de ce que le club peut produire.</p>',
                'statut'  => Article::STATUT_PUBLIE,
                'date'    => new \DateTimeImmutable('2025-03-10'),
            ],
            [
                'titre'   => 'Ouverture des inscriptions 2025-2026',
                'contenu' => '<p>Les inscriptions pour la saison 2025-2026 sont ouvertes. Micro-basket, mini-basket, compétition, basket loisir — il y a une place pour toutes.</p>',
                'statut'  => Article::STATUT_PUBLIE,
                'date'    => new \DateTimeImmutable('2025-06-01'),
            ],
            [
                'titre'   => 'Projet sport-études — premiers contacts avec la Cité Scolaire',
                'contenu' => '<p>La MABB a entamé les premières discussions avec la Cité Scolaire d\'Amiens pour la mise en place d\'une section sport-études basket féminin.</p>',
                'statut'  => Article::STATUT_BROUILLON,
                'date'    => null,
            ],
        ];

        foreach ($articles as $data) {
            $article = new Article();
            $article->setTitre($data['titre']);
            $article->setContenu($data['contenu']);
            $article->setStatut($data['statut']);
            $article->setPublishedAt($data['date']);
            $manager->persist($article);
        }

        $manager->flush();
    }
}
