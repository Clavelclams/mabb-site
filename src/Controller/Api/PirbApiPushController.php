<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\PushToken;
use App\Entity\Core\User;
use App\Repository\Core\PushTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiPushController — [Bloc K, 13/07/2026] enregistrement des appareils.
 *
 *   POST   /api/pirb/push/token → l'app déclare son jeton (à chaque ouverture)
 *   DELETE /api/pirb/push/token → l'app le retire (à la déconnexion)
 *
 * POURQUOI L'APP RÉENVOIE SON JETON À CHAQUE OUVERTURE, ET PAS UNE FOIS POUR
 * TOUTES : un jeton Expo peut changer (réinstallation, restauration de sauvegarde,
 * mise à jour du système). Réenregistrer est idempotent et coûte une requête :
 * c'est le prix d'un push qui arrive vraiment.
 *
 * LE JETON EST RATTACHÉ AU USER CONNECTÉ, jamais à un id passé en paramètre.
 * Sinon n'importe qui pourrait faire pousser ses notifications sur le téléphone
 * de quelqu'un d'autre, ou pire, détourner les siennes.
 *
 * DÉCONNEXION = SUPPRESSION DU JETON. Si une joueuse se déconnecte sur le
 * téléphone d'une copine, elle ne doit plus recevoir ses convocations dessus.
 * C'est du bon sens, et c'est aussi du RGPD.
 */
class PirbApiPushController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PushTokenRepository $tokenRepo,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/pirb/push/token', name: 'api_pirb_push_token', methods: ['POST'])]
    public function enregistrer(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? trim((string) ($payload['token'] ?? '')) : '';
        $plateforme = is_array($payload) ? (string) ($payload['plateforme'] ?? '') : '';

        // Garde-fou de forme : un jeton Expo commence toujours ainsi. Ça évite de
        // stocker n'importe quoi et d'envoyer des requêtes vouées à l'échec.
        if ($token === '' || !str_starts_with($token, 'ExponentPushToken[')) {
            return new JsonResponse(['error' => 'Jeton invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $plateforme = in_array($plateforme, [PushToken::PLATEFORME_IOS, PushToken::PLATEFORME_ANDROID], true)
            ? $plateforme
            : null;

        $existant = $this->tokenRepo->findOneByToken($token);

        if ($existant !== null) {
            // Le même appareil, mais peut-être une AUTRE utilisatrice (téléphone
            // partagé, revendu). On le rattache à celle qui est connectée MAINTENANT.
            if ($existant->getUser()?->getId() !== $user->getId()) {
                $this->tokenRepo->supprimerToken($token);
                $this->em->persist(new PushToken($user, $token, $plateforme));
                $this->logger->info('Push : appareil réattribué à un autre compte');
            } else {
                $existant->toucher($plateforme);
            }
        } else {
            $this->em->persist(new PushToken($user, $token, $plateforme));
        }

        $this->em->flush();

        return new JsonResponse(['enregistre' => true]);
    }

    #[Route('/api/pirb/push/token', name: 'api_pirb_push_token_suppr', methods: ['DELETE'])]
    public function supprimer(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($request->getContent(), true);
        $token = is_array($payload) ? trim((string) ($payload['token'] ?? '')) : '';

        if ($token !== '') {
            $existant = $this->tokenRepo->findOneByToken($token);
            // On ne supprime QUE son propre jeton : sinon on pourrait couper les
            // notifications de n'importe qui en devinant un jeton.
            if ($existant !== null && $existant->getUser()?->getId() === $user->getId()) {
                $this->tokenRepo->supprimerToken($token);
            }
        }

        return new JsonResponse(['supprime' => true]);
    }
}
