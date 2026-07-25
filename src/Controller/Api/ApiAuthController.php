<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\ApiToken;
use App\Entity\Core\ConnexionLog;
use App\Entity\Core\User;
use App\Repository\Core\ApiTokenRepository;
use App\Repository\Core\ConnexionLogRepository;
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
        ConnexionLogRepository $logs,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $email    = is_array($data) ? trim((string) ($data['email'] ?? '')) : '';
        $password = is_array($data) ? (string) ($data['password'] ?? '') : '';
        $appareil = is_array($data) ? ($data['appareil'] ?? null) : null;

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'Email et mot de passe requis.'], Response::HTTP_BAD_REQUEST);
        }

        $ip = $request->getClientIp();
        $ua = $request->headers->get('User-Agent');

        // ── ANTI BRUTE-FORCE [audit sécu 13/07] ──────────────────────────
        // AVANT : rien — un attaquant pouvait tester des mots de passe en
        // boucle sur /api/auth/login (le firewall est security:false ici,
        // donc AUCUN mécanisme Symfony ne protégeait cette route).
        // MAINTENANT : throttling sur NOS logs de connexion (ConnexionLog,
        // déjà en place) — 10 échecs par IP OU 5 échecs sur un même email
        // en 15 min → 429. Le seuil email (plus bas) protège un compte ciblé
        // même depuis plusieurs IP ; le seuil IP coupe le scan large.
        if ($logs->countFailuresByIp((string) $ip, 15) >= 10
            || $logs->countFailuresByEmail($email, 15) >= 5
        ) {
            return new JsonResponse(
                ['error' => 'Trop de tentatives. Réessaie dans quelques minutes.'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        // Message UNIQUE que le compte existe ou non (anti-énumération d'emails)
        if (!$user instanceof User || !$hasher->isPasswordValid($user, $password)) {
            // [audit sécu 13/07] Le login API contourne le firewall → aucun
            // LoginFailureEvent n'est émis → les échecs API n'étaient PAS
            // loggés (invisible dans l'admin, et le throttling ci-dessus
            // n'aurait rien à compter). On loggue nous-mêmes.
            $em->persist(ConnexionLog::echec(
                $email,
                $user instanceof User ? $user : null,
                $ip,
                $ua,
                'identifiants_invalides',
                'api'
            ));
            $em->flush();
            return new JsonResponse(['error' => 'Identifiants invalides.'], Response::HTTP_UNAUTHORIZED);
        }

        // Trace de connexion réussie (même registre que le web).
        $em->persist(ConnexionLog::succes($user, $ip, $ua, 'api'));

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
