<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Core\Club;
use App\Security\Tenant\TenantResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * [V2.4m] Expose le CLUB ACTIF aux templates : `club_actif()`.
 *
 * POURQUOI : la navbar Manager conditionnait ses items (Coach, Secrétariat,
 * OTM, Demandes) à une variable `club` que CHAQUE contrôleur devait penser à
 * passer. Ceux qui l'oubliaient (ex. dashboard coach) affichaient une navbar
 * amputée. La navbar résout désormais le club elle-même via TenantResolver —
 * plus aucun contrôleur à modifier, plus d'items qui disparaissent.
 *
 * Try/catch : hors session (page d'erreur, CLI de rendu…) on renvoie null
 * plutôt que de casser le rendu.
 */
class ClubExtension extends AbstractExtension
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('club_actif', [$this, 'getClubActif']),
        ];
    }

    public function getClubActif(): ?Club
    {
        try {
            return $this->tenantResolver->getCurrentClub();
        } catch (\Throwable) {
            return null;
        }
    }
}
