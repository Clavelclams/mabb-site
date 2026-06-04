<?php

namespace App\EventListener;

use App\Entity\Core\User;
use App\Security\Tenant\TenantResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * PendingUserSubscriber — redirige les Users avec demande pending vers /en-attente.
 *
 * Comportement :
 *   - User connecté sur manager.* ou pirb.* avec uniquement des UserClubRole pending
 *     → redirigé vers /en-attente, peu importe l'URL demandée
 *   - User avec au moins un UserClubRole active → passe normalement
 *   - User non connecté → laisse passer (le firewall fait son job)
 *
 * Routes exemptes (toujours accessibles aux pending) :
 *   - /en-attente (sinon boucle infinie)
 *   - /login (login form)
 *   - /inscription (page d'inscription)
 *   - /deconnexion (pour pouvoir se logout)
 */
class PendingUserSubscriber implements EventSubscriberInterface
{
    private const ROUTES_EXEMPTES = [
        'manager_en_attente',
        'manager_login',
        'manager_inscription',
        'manager_logout',
        'pirb_login',     // PIRB peut aussi être impacté (V2)
        'pirb_logout',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TenantResolver $tenantResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité 0 = après le firewall (qui a priorité plus haute), avant le controller
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = $request->getHost();

        // On agit uniquement sur les sous-domaines manager/pirb
        if (!str_starts_with($host, 'manager.') && !str_starts_with($host, 'pirb.')) {
            return;
        }

        // Si la route demandée est exempte, on laisse passer
        $route = $request->attributes->get('_route');
        if (in_array($route, self::ROUTES_EXEMPTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // non connecté → le firewall gère
        }

        // Si l'user a au moins un UserClubRole validé → passe normalement
        if (!empty($this->tenantResolver->getUserClubs($user))) {
            return;
        }

        // Si l'user a une demande pending → redirect /en-attente
        if ($this->tenantResolver->hasPendingMembership($user)) {
            $event->setResponse(new RedirectResponse(
                $this->urlGenerator->generate('manager_en_attente')
            ));
        }

        // Sinon (cas pathologique : user connecté sans aucun UserClubRole) :
        // on laisse passer, le controller affichera "Aucun club actif".
    }
}
