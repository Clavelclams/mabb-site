<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\HighlightEmbedExtension;
use PHPUnit\Framework\TestCase;

/**
 * B3 — Test PIRB V1.2c HighlightEmbedExtension.
 *
 * Garantit que la détection de plateforme (YouTube/Insta/TikTok) marche,
 * et que les URL d'embed iframe YouTube sont bien construites.
 */
class HighlightEmbedExtensionTest extends TestCase
{
    private HighlightEmbedExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new HighlightEmbedExtension();
    }

    public function testDetectYouTubeFromWatchUrl(): void
    {
        self::assertSame('youtube', $this->ext->detectPlatform('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    }

    public function testDetectYouTubeFromShortUrl(): void
    {
        self::assertSame('youtube', $this->ext->detectPlatform('https://youtu.be/dQw4w9WgXcQ'));
    }

    public function testDetectYouTubeFromShortsUrl(): void
    {
        self::assertSame('youtube', $this->ext->detectPlatform('https://www.youtube.com/shorts/dQw4w9WgXcQ'));
    }

    public function testDetectInstagram(): void
    {
        self::assertSame('instagram', $this->ext->detectPlatform('https://www.instagram.com/p/ABC123/'));
    }

    public function testDetectInstagramReel(): void
    {
        self::assertSame('instagram', $this->ext->detectPlatform('https://www.instagram.com/reel/ABC123/'));
    }

    public function testDetectTikTok(): void
    {
        self::assertSame('tiktok', $this->ext->detectPlatform('https://www.tiktok.com/@user/video/123456'));
    }

    public function testUnknownPlatformReturnsAutre(): void
    {
        self::assertSame('autre', $this->ext->detectPlatform('https://vimeo.com/12345'));
        self::assertSame('autre', $this->ext->detectPlatform(''));
    }

    public function testEmbedYouTubeProducesIframeWithCorrectId(): void
    {
        $html = $this->ext->embedHtml('https://youtu.be/dQw4w9WgXcQ');

        self::assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $html);
        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('allowfullscreen', $html);
    }

    public function testEmbedHtmlSpecialcharsEscapesQuotes(): void
    {
        // Sécurité : pas de cassure du HTML via une URL contenant des guillemets
        $html = $this->ext->embedHtml('https://example.com/"><script>alert(1)</script>');

        // Le <script> ne doit JAMAIS apparaître brut dans le HTML retourné
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        // Les guillemets doivent avoir été échappés
        self::assertStringContainsString('&quot;', $html);
    }
}
