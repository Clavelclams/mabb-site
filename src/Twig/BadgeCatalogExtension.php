<?php

declare(strict_types=1);

namespace App\Twig;

use App\Gamification\BadgeCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose le catalogue de badges (BadgeCatalog::all()) à Twig.
 *
 * Utilisé par PIRB pour afficher les badges épinglés sans avoir à passer
 * le catalogue depuis chaque controller. PIRB lit le code badge stocké
 * dans Joueur.badgesEpingles et fait un lookup via cette fonction.
 */
final class BadgeCatalogExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pirb_badge_catalogue', [$this, 'catalogue']),
            new TwigFunction('pirb_badge_info', [$this, 'badgeInfo']),
        ];
    }

    /**
     * Retourne le catalogue complet (code => {nom, description, icone, axe}).
     *
     * @return array<string, array{nom: string, description: string, icone: string, axe: string, par_saison: bool}>
     */
    public function catalogue(): array
    {
        return BadgeCatalog::all();
    }

    /**
     * Retourne les infos d'un badge précis, ou null si code inconnu.
     *
     * @return array{nom: string, description: string, icone: string, axe: string, par_saison: bool}|null
     */
    public function badgeInfo(string $code): ?array
    {
        $cat = BadgeCatalog::all();
        return $cat[$code] ?? null;
    }
}
