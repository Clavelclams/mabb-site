<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\ApiToken;
use App\Entity\Core\User;
use App\Repository\Core\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ApiAuthController — [B4 phase 1, 06/07/2026] login/logout de l'API mobile.
 *
 *   POST /api/auth/login  {email, password, appareil?}
 *     → 200 {token, expiresAt, user:{id, prenom, nom, email}}
 *     → 401 {error} si identifiants invalides (message générique, anti-énumération)
 *
 *   POST /api/auth/logout (Bearer) → révoque le jeton présenté.
 *
 * Le jeton est celui d'ApiToken (opaque, hashé en base, 30 jours).
 * Les COMPTES sont les mêmes que pirb.mabb.fr : la joueuse utilise
 * l'email/mot de passe de son espace web.
 */
class ApiAuthController extends AbstractController
{
    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(
        Request $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email    = is_array($data) ? trim((string) ($data['email'] ?? '')) : '';
        $password = is_array($data) ? (string) ($data['password'] ?? '') : '';
        $appareil = is_array($data) ? ($data['appareil'] ?? null) : null;

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Email et mot de passe requis.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        // Message UNIQUE que le compte existe ou non (anti-énumération d'emails)
        if (!$user instanceof User || !$hasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['error' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
        }

        [$token, $clair] = ApiToken::creerPour($user, is_string($appareil) ? $appareil : null);
        $em->persist($token);
        $em->flush();

        return new JsonResponse([
            'token'     => $clair, // montré UNE seule fois — l'app le stocke
            'expiresAt' => $token->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'user'      => [
                'id'     => $user->getId(),
                'prenom' => $user->getPrenom(),
                'nom'    => $user->getNom(),
                'email'  => $user->getEmail(),
            ],
        ]);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(
        Request $request,
        ApiTokenRepository $tokens,
        EntityManagerInterface $em,
    ): JsonResponse {
        // Révoque le jeton porté par CETTE requête (déjà validé par le firewall)
        $auth = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            $token = $tokens->findValide(substr($auth, 7));
            if ($token !== null) {
                $em->remove($token);
                $em->flush();
            }
        }
        return new JsonResponse(['success' => true]);
    }
}
