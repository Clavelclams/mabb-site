<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Core\Notification;
use App\Entity\Core\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * NotificationService
 *
 * Service léger pour créer des notifications in-app.
 *
 * Convention importante : ce service fait `persist()` mais PAS `flush()`.
 * La responsabilité du flush appartient au caller — ça évite les double-flush
 * et permet de grouper plusieurs opérations dans une seule transaction.
 *
 * Exemple d'utilisation :
 *   // Dans un controller :
 *   $this->notifService->creer(
 *       $joueur->getUser(),
 *       $club,
 *       Notification::TYPE_SHOT_CHART_VALIDEE,
 *       message: 'Ta séance du 25/06 a été validée.',
 *       lienRoute: 'pirb_shot_chart'
 *   );
 *   $this->em->flush(); // flush séance + notif en une transaction
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Crée et persiste une notification (sans flush).
     *
     * @param User        $destinataire  Qui reçoit la notification
     * @param Club        $club          Club de contexte (multi-tenant)
     * @param string      $type          Une des constantes Notification::TYPE_*
     * @param string|null $message       Texte libre optionnel (motif, détail…)
     * @param string|null $lienRoute     Nom de route Symfony pour le lien "Voir"
     */
    public function creer(
        User    $destinataire,
        Club    $club,
        string  $type,
        ?string $message   = null,
        ?string $lienRoute = null,
    ): Notification {
        $notif = (new Notification())
            ->setDestinataire($destinataire)
            ->setClub($club)
            ->setType($type)
            ->setMessage($message)
            ->setLienRoute($lienRoute);

        $this->em->persist($notif);

        return $notif;
    }
}
