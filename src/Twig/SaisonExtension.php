<?php

namespace App\Twig;

use App\Service\SaisonService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SaisonExtension extends AbstractExtension
{
    public function __construct(
        private SaisonService $saisonService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('saison_active', [$this, 'getSaisonActive']),
            new TwigFunction('saisons_disponibles', [$this, 'getSaisonsDisponibles']),
        ];
    }

    public function getSaisonActive(): string
    {
        return $this->saisonService->getSaisonActive();
    }

    public function getSaisonsDisponibles(): array
    {
        return $this->saisonService->getSaisonsDisponibles();
    }
}
