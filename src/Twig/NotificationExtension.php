<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\Core\NotificationRepository;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * NotificationExtension
 *
 * Expose la fonction Twig `pirb_unread_count()` utilisée dans `pirb/base.html.twig`
 * pour afficher le badge rouge sur la cloche de notifications.
 *
 * Pourquoi une Twig Extension plutôt que passer la variable depuis chaque controller ?
 *   Le badge doit apparaître sur TOUTES les pages PIRB (8+ controllers).
 *   Modifier chaque controller pour injecter un compte de notifs serait
 *   répétitif et fragile (oubli = badge absent). Une extension centralisée
 *   résout ça en un seul endroit.
 *
 * Coût : 1 requête SQL COUNT() indexée par rendu de page PIRB.
 *   Acceptable. Si le site scale, ajouter un cache Redis/APCu ici.
 *
 * Sécurité : retourne 0 si pas connecté ou club non résolu — jamais d'exception.
 */
class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly TenantResolver         $tenantResolver,
        private readonly Security               $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pirb_unread_count', $this->getUnreadCount(...)),
        ];
    }

    /**
     * Retourne le nombre de notifications non lues de l'utilisateur courant
     * dans le club actif. Retourne 0 en cas d'erreur ou d'état incomplet.
     */
    public function getUnreadCount(): int
    {
        try {
            $user = $this->security->getUser();
            $club = $this->tenantResolver->getCurrentClub();

            if ($user === null || $club === null) {
                return 0;
            }

            return $this->notifRepo->countUnreadByUserAndClub($user, $club);
        } catch (\Throwable) {
            // Ne pas casser toute la page pour un badge — retourner 0 silencieusement
            return 0;
        }
    }
}
