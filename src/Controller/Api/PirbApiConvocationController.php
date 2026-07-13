<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\Convocation;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiConvocationController — [Bloc E, 13/07/2026]
 *
 * Les convocations dans l'app, en NATIF. Jusqu'ici elles n'existaient que dans
 * l'espace web (PirbConvocationsController), affiché en WebView dans un menu.
 * Deux problèmes : une convocation ne se cherche pas dans un menu, et une app
 * majoritairement WebView se fait retoquer par Apple (Guideline 4.2).
 *
 *   GET  /api/pirb/convocations              → { aVenir: [], passees: [] }
 *   POST /api/pirb/convocations/{id}/repondre → la convocation mise à jour
 *
 * SÉCURITÉ — les trois garde-fous du contrôleur web, repris à l'identique :
 *   1. IDOR : la convocation doit appartenir au joueur du user connecté. Sinon
 *      403 + log. Sans ça, n'importe qui pourrait répondre à la place d'une
 *      autre en changeant l'id dans l'URL.
 *   2. Liste blanche : la réponse doit être dans Convocation::REPONSES.
 *   3. Verrou métier : plus de réponse après la date du match.
 *
 * PAS DE CSRF ICI, ET C'EST VOULU. Le CSRF protège contre un site tiers qui
 * ferait poster le navigateur à l'insu de l'utilisateur, en s'appuyant sur le
 * cookie de session envoyé AUTOMATIQUEMENT. Cette API s'authentifie par un
 * Bearer token (firewall api), que le navigateur n'envoie jamais tout seul :
 * l'attaque n'existe pas. Ajouter du CSRF ici serait du bruit.
 *
 * CONTRAT : la forme du JSON est fixée par `Pirb store/src/types/pirb.ts`.
 * Toute évolution = modifier LES DEUX.
 */
class PirbApiConvocationController extends AbstractController
{
    private const MAX_PASSEES = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConvocationRepository $convocationRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/convocations
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/convocations', name: 'api_pirb_convocations', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        $maintenant = new \DateTimeImmutable();

        $aVenir = $this->convocationRepo->createQueryBuilder('c')
            ->join('c.rencontre', 'r')
            ->addSelect('r')
            ->where('c.joueur = :j')
            ->andWhere('r.date >= :now')
            ->setParameter('j', $joueur)
            ->setParameter('now', $maintenant)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();

        $passees = $this->convocationRepo->createQueryBuilder('c')
            ->join('c.rencontre', 'r')
            ->addSelect('r')
            ->where('c.joueur = :j')
            ->andWhere('r.date < :now')
            ->setParameter('j', $joueur)
            ->setParameter('now', $maintenant)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults(self::MAX_PASSEES)
            ->getQuery()
            ->getResult();

        return new JsonResponse([
            'aVenir'  => array_map(fn (Convocation $c) => $this->serialiser($c), $aVenir),
            'passees' => array_map(fn (Convocation $c) => $this->serialiser($c), $passees),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/pirb/convocations/{id}/repondre
    // ─────────────────────────────────────────────────────────────────────

    #[Route(
        '/api/pirb/convocations/{id}/repondre',
        name: 'api_pirb_convocations_repondre',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function repondre(Request $request, Convocation $convocation): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        // GARDE-FOU 1 — IDOR. On ne se fie JAMAIS à l'id de l'URL : on vérifie
        // que cette convocation est bien celle de la joueuse connectée.
        if ($convocation->getJoueur()?->getId() !== $joueur->getId()) {
            $this->logger->warning('API PIRB : tentative de réponse convocation IDOR', [
                'joueur_id'      => $joueur->getId(),
                'convocation_id' => $convocation->getId(),
                'ip'             => $request->getClientIp(),
            ]);
            return new JsonResponse(
                ['error' => 'Cette convocation ne te concerne pas.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], Response::HTTP_BAD_REQUEST);
        }

        // GARDE-FOU 2 — liste blanche. Aucune valeur libre ne rentre en base.
        $reponse = (string) ($payload['reponse'] ?? '');
        if (!in_array($reponse, Convocation::REPONSES, true)) {
            return new JsonResponse(
                ['error' => 'Réponse invalide. Attendu : present, absent ou incertain.'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // GARDE-FOU 3 — verrou métier. On ne répond pas à un match déjà joué.
        if (!$this->peutRepondre($convocation)) {
            return new JsonResponse(
                ['error' => 'Cette rencontre est déjà passée, tu ne peux plus répondre.'],
                Response::HTTP_CONFLICT
            );
        }

        $motif = trim((string) ($payload['motif'] ?? ''));

        $convocation->setReponse($reponse);
        $convocation->setMotif($motif !== '' ? $motif : null);
        $this->em->flush();

        $this->logger->info('API PIRB : réponse convocation enregistrée', [
            'convocation_id' => $convocation->getId(),
            'joueur_id'      => $joueur->getId(),
            'reponse'        => $reponse,
        ]);

        return new JsonResponse($this->serialiser($convocation));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Interne
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Le SERVEUR est autoritaire sur le droit de répondre. L'app reçoit
     * `peutRepondre` et se contente de griser ses boutons : elle n'a pas à
     * recalculer la règle métier, et elle ne peut pas la contourner.
     */
    private function peutRepondre(Convocation $convocation): bool
    {
        $date = $convocation->getRencontre()?->getDate();
        return $date !== null && $date >= new \DateTimeImmutable();
    }

    /** @return array<string, mixed> */
    private function serialiser(Convocation $convocation): array
    {
        $rencontre = $convocation->getRencontre();

        return [
            'id'           => $convocation->getId(),
            'reponse'      => $convocation->getReponse(),
            'motif'        => $convocation->getMotif(),
            'repondueAt'   => $convocation->getRepondueAt()?->format(\DateTimeInterface::ATOM),
            'peutRepondre' => $this->peutRepondre($convocation),
            'rencontre'    => $rencontre !== null ? [
                'id'           => $rencontre->getId(),
                'date'         => $rencontre->getDate()?->format(\DateTimeInterface::ATOM),
                'adversaire'   => $rencontre->getAdversaire(),
                'lieu'         => $rencontre->getLieu(),
                'domicile'     => $rencontre->isDomicile(),
                'division'     => $rencontre->getDivision(),
                'equipe'       => $rencontre->getEquipe()?->getNom(),
                'scoreEquipe'  => $rencontre->getScoreEquipe(),
                'scoreAdverse' => $rencontre->getScoreAdverse(),
            ] : null,
        ];
    }

    private function joueurOu404(): Joueur|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            return new JsonResponse(
                ['error' => 'Aucune fiche joueuse liée à ce compte. Contacte le staff du club.'],
                Response::HTTP_NOT_FOUND
            );
        }
        return $joueur;
    }
}
