<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

class SaisonService
{
    private const SAISONS_DISPONIBLES = ['2026-2027', '2025-2026', '2024-2025'];
    private const SAISON_DEFAULT = '2026-2027';

    public function __construct(private RequestStack $requestStack) {}

    public function getSaisonsDisponibles(): array
    {
        return self::SAISONS_DISPONIBLES;
    }

    public function getSaisonCourante(): string
    {
        $mois = (int) date('n');
        $annee = (int) date('Y');
        if ($mois >= 9) {
            return $annee . '-' . ($annee + 1);
        }
        return ($annee - 1) . '-' . $annee;
    }

    public function getSaisonPrecedente(string $saison): string
    {
        [$debut, $fin] = explode('-', $saison);
        return ($debut - 1) . '-' . ($fin - 1);
    }

    public function getSaisonActive(): string
    {
        $session = $this->requestStack->getSession();
        return $session->get('active_saison', self::SAISON_DEFAULT);
    }

    public function isValide(string $saison): bool
    {
        return in_array($saison, self::SAISONS_DISPONIBLES, true);
    }
}
