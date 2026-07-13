<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\RgpdRequest;
use App\Entity\Core\User;
use App\Repository\Core\RgpdRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiCompteController — [Bloc H, 13/07/2026]
 *
 * Le compte et les droits RGPD, depuis l'app.
 *
 *   GET  /api/pirb/compte                     → état du compte + demande en cours
 *   POST /api/pirb/compte/suppression         → demande d'effacement (RGPD art. 17)
 *
 * POURQUOI C'EST OBLIGATOIRE, ET PAS UNE FAVEUR :
 * Apple, App Store Review Guideline 5.1.1(v) : toute app qui donne accès à un
 * compte doit permettre d'en demander la SUPPRESSION **depuis l'app**. Un lien
 * vers un site, ou un « écris-nous un mail », ne suffit plus. C'est un motif de
 * rejet fréquent et bête.
 *
 * ON NE SUPPRIME RIEN IMMÉDIATEMENT, ET C'EST VOULU : on crée une RgpdRequest,
 * traitée par un admin sous 30 jours (délai légal). Deux raisons. D'abord ce
 * sont des comptes de MINEURES rattachés à un club : une suppression instantanée
 * et irréversible sur un coup de tête, sans que le club ou les parents en sachent
 * rien, serait irresponsable. Ensuite le club a des obligations fédérales
 * (licences FFBB, feuilles de match) qui interdisent de tout effacer sur-le-champ.
 * L'app le DIT clairement à l'utilisatrice : c'est une demande, elle est traitée,
 * elle a un délai. La transparence vaut mieux qu'un faux bouton magique.
 *
 * PAS DE CSRF (firewall API stateless, authentification par Bearer token : le
 * navigateur n'envoie jamais ce jeton tout seul, l'attaque CSRF n'existe pas).
 */
class PirbApiCompteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RgpdRequestRepository $rgpdRepo,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/pirb/compte', name: 'api_pirb_compte', methods: ['GET'])]
    public function compte(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        $enCours = $this->rgpdRepo->findActivePendingForUser($user);

        return new JsonResponse([
            'email'                  => $user->getEmail(),
            'prenom'                 => $user->getPrenom(),
            'nom'                    => $user->getNom(),
            // true = une demande d'effacement est déjà déposée : l'app grise le
            // bouton et affiche l'état, au lieu d'empiler les demandes.
            'suppressionDemandee'    => $enCours !== null,
        ]);
    }

    #[Route('/api/pirb/compte/suppression', name: 'api_pirb_compte_suppression', methods: ['POST'])]
    public function demanderSuppression(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }

        // Idempotence : une demande en cours suffit. On répond 200 (et pas une
        // erreur) : du point de vue de l'utilisatrice, le résultat voulu est déjà
        // atteint. Inutile de l'inquiéter avec un message d'échec.
        if ($this->rgpdRepo->findActivePendingForUser($user) !== null) {
            return new JsonResponse([
                'suppressionDemandee' => true,
                'message'             => 'Ta demande est déjà enregistrée, elle est en cours de traitement.',
            ]);
        }

        $payload = json_decode($request->getContent(), true);
        $motif = is_array($payload) ? trim((string) ($payload['motif'] ?? '')) : '';

        $demande = new RgpdRequest($user, RgpdRequest::TYPE_EFFACEMENT);
        $demande->setMotifUser($motif !== '' ? $motif : null);
        $this->em->persist($demande);
        $this->em->flush();

        $this->logger->info('API PIRB : demande RGPD effacement créée', [
            'rgpd_id' => $demande->getId(),
            'user_id' => $user->getId(),
        ]);

        return new JsonResponse([
            'suppressionDemandee' => true,
            'message'             => 'Demande enregistrée. Un administrateur la traite sous 30 jours (délai légal).',
        ], Response::HTTP_CREATED);
    }
}
