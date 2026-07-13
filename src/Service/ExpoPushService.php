<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\User;
use App\Repository\Core\PushTokenRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ExpoPushService — [Bloc K, 13/07/2026] l'envoi des notifications push.
 *
 * On passe par l'API d'Expo (gratuite, sans clé) plutôt que de parler
 * directement à Apple (APNs) et Google (FCM). Pourquoi : ce sont deux protocoles
 * différents, deux systèmes de certificats, deux fois le travail et deux fois les
 * bugs. Expo fait le pont. Le jour où on veut s'en passer, seul ce fichier change.
 *
 * TROIS RÈGLES DE PRODUCTION, ET ELLES NE SONT PAS FACULTATIVES :
 *
 *  1. UN PUSH NE DOIT JAMAIS FAIRE ÉCHOUER L'ACTION MÉTIER. Si Expo est en panne,
 *     la convocation doit quand même être enregistrée. Tout est donc dans un
 *     try/catch qui LOGGE et continue. Une notification ratée est un désagrément ;
 *     une convocation perdue est un bug.
 *
 *  2. ON NETTOIE LES APPAREILS MORTS. Quand Expo répond « DeviceNotRegistered »
 *     (app désinstallée, jeton périmé), on supprime le jeton. Sans ça, la table
 *     grossit indéfiniment et on envoie dans le vide à chaque fois.
 *
 *  3. ON ENVOIE PAR PAQUETS. L'API accepte jusqu'à 100 messages par requête.
 *     Convoquer 12 joueuses = 1 appel réseau, pas 12.
 */
class ExpoPushService
{
    private const URL = 'https://exp.host/--/api/v2/push/send';
    private const TAILLE_LOT = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly PushTokenRepository $tokenRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Envoie une notification à tous les appareils de ces utilisateurs.
     *
     * @param User[]               $users
     * @param array<string, mixed> $data  Charge utile lue par l'app au tap
     *                                    (ex. ['type' => 'convocation']) pour
     *                                    ouvrir le bon écran.
     */
    public function envoyerAUsers(array $users, string $titre, string $corps, array $data = []): void
    {
        $jetons = $this->tokenRepo->jetonsPourUsers($users);
        if ($jetons === []) {
            // Personne n'a installé l'app, ou personne n'a accepté les notifs.
            // Ce n'est pas une erreur : on le note et on passe.
            $this->logger->info('Push : aucun appareil enregistré pour ces utilisateurs', [
                'nb_users' => count($users),
            ]);
            return;
        }

        foreach (array_chunk($jetons, self::TAILLE_LOT) as $lot) {
            $this->envoyerLot($lot, $titre, $corps, $data);
        }
    }

    /**
     * @param string[]             $jetons
     * @param array<string, mixed> $data
     */
    private function envoyerLot(array $jetons, string $titre, string $corps, array $data): void
    {
        $messages = array_map(fn (string $jeton) => [
            'to'       => $jeton,
            'title'    => $titre,
            'body'     => $corps,
            'data'     => $data,
            'sound'    => 'default',
            'priority' => 'high',
        ], $jetons);

        try {
            $reponse = $this->http->request('POST', self::URL, [
                'headers' => [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json'    => $messages,
                'timeout' => 8, // OVH mutualisé : on ne bloque pas la requête du coach
            ]);

            $resultat = $reponse->toArray(false);
            $tickets = $resultat['data'] ?? [];

            // Expo renvoie un ticket par message, dans le MÊME ordre que l'envoi.
            foreach ($tickets as $i => $ticket) {
                if (($ticket['status'] ?? '') !== 'error') {
                    continue;
                }

                $motif = $ticket['details']['error'] ?? 'inconnu';
                $jeton = $jetons[$i] ?? null;

                // RÈGLE 2 : l'appareil n'existe plus, on nettoie.
                if ($motif === 'DeviceNotRegistered' && $jeton !== null) {
                    $this->tokenRepo->supprimerToken($jeton);
                    $this->logger->info('Push : jeton périmé supprimé', ['motif' => $motif]);
                    continue;
                }

                $this->logger->warning('Push : envoi refusé par Expo', [
                    'motif'   => $motif,
                    'message' => $ticket['message'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // RÈGLE 1 : on ne fait JAMAIS échouer l'action métier pour un push.
            $this->logger->error('Push : échec de l\'appel à Expo', [
                'erreur' => $e->getMessage(),
                'nb'     => count($jetons),
            ]);
        }
    }
}
