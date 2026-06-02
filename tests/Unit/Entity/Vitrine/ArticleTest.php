<?php

namespace App\Tests\Unit\Entity\Vitrine;

use App\Entity\Vitrine\Article;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la génération automatique de slug lors de la persistance.
 *
 * Le slug est généré dans onPrePersist() à partir du titre, en :
 *   - mettant en minuscule
 *   - retirant les accents (français)
 *   - supprimant les caractères spéciaux
 *   - ajoutant un suffixe aléatoire de 6 caractères pour garantir l'unicité
 *
 * Ces tests garantissent qu'un changement de la logique de slugification
 * ne casse pas la conformité avec ce contrat.
 */
class ArticleTest extends TestCase
{
    public function testOnPrePersistGeneratesSlugFromTitle(): void
    {
        $article = new Article();
        $article->setTitre('Mon Premier Article');

        $article->onPrePersist();

        $this->assertNotNull($article->getSlug());
        $this->assertStringStartsWith('mon-premier-article-', $article->getSlug(),
            'Le slug doit commencer par le titre slugifié.');
    }

    public function testSlugHandlesFrenchAccents(): void
    {
        $article = new Article();
        $article->setTitre('Saison 2025/26 : objectif Pré-Nationale à Amiens');

        $article->onPrePersist();

        $slug = $article->getSlug();
        $this->assertStringStartsWith('saison-202526-objectif-pre-nationale-a-amiens-', $slug);
        $this->assertStringNotContainsString('é', $slug, 'Aucun accent ne doit subsister.');
        $this->assertStringNotContainsString('è', $slug);
    }

    public function testSlugRemovesSpecialChars(): void
    {
        $article = new Article();
        $article->setTitre('Match #42 — 100% de réussite !!!');

        $article->onPrePersist();

        $slug = $article->getSlug();
        // Vérifie qu'on n'a que des chars autorisés [a-z0-9-]
        $base = preg_replace('/-[a-z0-9]{6}$/', '', $slug); // retire le suffixe aléatoire
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $base,
            'Le slug ne doit contenir que [a-z0-9-].');
    }

    public function testOnPrePersistDoesNotOverwriteExistingSlug(): void
    {
        $article = new Article();
        $article->setTitre('Un titre quelconque');
        $article->setSlug('slug-perso-defini-a-la-main');

        $article->onPrePersist();

        $this->assertSame('slug-perso-defini-a-la-main', $article->getSlug(),
            'Si un slug est déjà défini, onPrePersist ne doit pas l\'écraser.');
    }

    public function testSlugIncludesRandomSuffixForUniqueness(): void
    {
        // Deux articles avec le même titre doivent avoir des slugs différents
        $a1 = new Article(); $a1->setTitre('Titre identique'); $a1->onPrePersist();
        $a2 = new Article(); $a2->setTitre('Titre identique'); $a2->onPrePersist();

        $this->assertNotSame($a1->getSlug(), $a2->getSlug(),
            'Le suffixe aléatoire doit rendre chaque slug unique.');
        $this->assertStringStartsWith('titre-identique-', $a1->getSlug());
        $this->assertStringStartsWith('titre-identique-', $a2->getSlug());
    }

    public function testPublicationDefaultStatus(): void
    {
        // Test simple : un article créé n'est pas publié par défaut
        $article = new Article();
        $this->assertFalse($article->isPublie(),
            'Un article doit être en brouillon par défaut, pas publié.');
    }
}
