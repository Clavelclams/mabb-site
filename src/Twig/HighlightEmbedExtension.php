<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Détection auto plateforme + embed HTML pour highlights vidéo PIRB.
 *
 * Plateformes supportées :
 *   - YouTube (youtu.be, youtube.com/watch, youtube.com/shorts)
 *   - Instagram (instagram.com/reel, instagram.com/p)
 *   - TikTok (tiktok.com/@user/video, vm.tiktok.com)
 *
 * Pour les autres URL : fallback lien externe simple.
 */
final class HighlightEmbedExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('highlight_platform', [$this, 'detectPlatform']),
            new TwigFunction('highlight_embed_html', [$this, 'embedHtml'], ['is_safe' => ['html']]),
            new TwigFunction('highlight_thumbnail', [$this, 'thumbnailUrl']),
        ];
    }

    /**
     * Retourne 'youtube', 'instagram', 'tiktok' ou 'autre'.
     */
    public function detectPlatform(string $url): string
    {
        if (preg_match('/(?:youtube\.com|youtu\.be)/i', $url)) {
            return 'youtube';
        }
        if (preg_match('/instagram\.com/i', $url)) {
            return 'instagram';
        }
        if (preg_match('/tiktok\.com/i', $url)) {
            return 'tiktok';
        }
        return 'autre';
    }

    /**
     * HTML embed iframe responsive pour chaque plateforme.
     */
    public function embedHtml(string $url): string
    {
        $platform = $this->detectPlatform($url);
        $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        if ($platform === 'youtube') {
            // Extrait l'ID vidéo YouTube
            $id = null;
            if (preg_match('/(?:youtu\.be\/|v=|shorts\/)([A-Za-z0-9_-]{11})/', $url, $m)) {
                $id = $m[1];
            }
            if ($id !== null) {
                return sprintf(
                    '<div style="position:relative; padding-top:56.25%%; border-radius:10px; overflow:hidden;">'
                    . '<iframe src="https://www.youtube.com/embed/%s" frameborder="0" allowfullscreen '
                    . 'style="position:absolute; top:0; left:0; width:100%%; height:100%%;"></iframe></div>',
                    $id
                );
            }
        }

        if ($platform === 'instagram') {
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px; padding:14px; '
                . 'background:linear-gradient(135deg, #833ab4, #fd1d1d, #fcb045); border-radius:10px; color:#fff; text-decoration:none; font-weight:700;">'
                . '<i class="bi bi-instagram" style="font-size:1.6rem;"></i> Voir sur Instagram</a>',
                $url
            );
        }

        if ($platform === 'tiktok') {
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:10px; padding:14px; '
                . 'background:#000; border:1px solid #25f4ee; border-radius:10px; color:#fe2c55; text-decoration:none; font-weight:700;">'
                . '<i class="bi bi-tiktok" style="font-size:1.6rem;"></i> Voir sur TikTok</a>',
                $url
            );
        }

        // Fallback générique
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:8px; '
            . 'padding:10px 16px; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.15); '
            . 'border-radius:10px; color:#cbd5e1; text-decoration:none;">'
            . '<i class="bi bi-play-circle"></i> Voir la vidéo</a>',
            $url
        );
    }

    /**
     * URL thumbnail YouTube si possible (pour preview rapide).
     */
    public function thumbnailUrl(string $url): ?string
    {
        if (preg_match('/(?:youtu\.be\/|v=|shorts\/)([A-Za-z0-9_-]{11})/', $url, $m)) {
            return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
        }
        return null;
    }
}
