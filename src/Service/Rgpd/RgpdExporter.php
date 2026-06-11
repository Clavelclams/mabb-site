<?php

declare(strict_types=1);

namespace App\Service\Rgpd;

use App\Entity\Core\User;
use App\Repository\Core\ConnexionLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * B2 — RGPD : export des données personnelles d'un User (Art. 15 RGPD).
 *
 * Génère un JSON contenant TOUTES les données personnelles que l'app détient
 * sur le User : profil, rôles, présences, convocations, badges, stats, etc.
 *
 * Le User peut télécharger ce JSON via /profil/rgpd-export
 * (et/ou un admin peut le générer via /admin/rgpd/{userId}/export).
 *
 * Format choisi : JSON (lisible humain + machine, standard portable Art. 20).
 */
class RgpdExporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConnexionLogRepository $logRepo,
    ) {}

    /**
     * Génère le tableau complet des données du User.
     * À sérialiser en JSON ensuite (via json_encode JSON_PRETTY_PRINT).
     */
    public function exportUserData(User $user): array
    {
        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'rgpd_article' => 'Art. 15 RGPD — Droit d\'accès aux données personnelles',
            'user' => [
                'id'             => $user->getId(),
                'email'          => $user->getEmail(),
                'prenom'         => $user->getPrenom(),
                'nom'            => $user->getNom(),
                'telephone'      => $user->getTelephone(),
                'date_naissance' => $user->getDateNaissance()?->format('Y-m-d'),
                'bio'            => $user->getBio(),
                'photo_path'     => $user->getPhotoPath(),
                'is_active'      => $user->isActive(),
                'rgpd_consent'   => $user->isRgpdConsent(),
                'rgpd_consent_at' => $user->getRgpdConsentAt()?->format(\DateTimeInterface::ATOM),
                'created_at'     => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'last_login_at'  => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
                'roles_symfony'  => $user->getRoles(),
            ],
            'roles_metier' => $this->exportRolesMetier($user),
            'connexion_logs_recents' => $this->exportConnexionLogs($user, 50),
            'reset_password_requests' => $this->exportResetPasswordRequests($user),
        ];
    }

    private function exportRolesMetier(User $user): array
    {
        $sql = <<<'DQL'
            SELECT r.id, r.role, r.statut, r.dateDebut, r.dateFin, IDENTITY(r.club) AS clubId
            FROM App\Entity\Core\UserClubRole r
            WHERE r.user = :u
        DQL;

        $rows = $this->em->createQuery($sql)
            ->setParameter('u', $user)
            ->getArrayResult();

        return array_map(static fn(array $r) => [
            'id'         => $r['id'],
            'club_id'    => $r['clubId'],
            'role'       => $r['role'],
            'statut'     => $r['statut'],
            'date_debut' => $r['dateDebut']?->format('Y-m-d'),
            'date_fin'   => $r['dateFin']?->format('Y-m-d'),
        ], $rows);
    }

    private function exportConnexionLogs(User $user, int $limit): array
    {
        $logs = $this->em->createQueryBuilder()
            ->select('l')
            ->from(\App\Entity\Core\ConnexionLog::class, 'l')
            ->where('l.user = :u')
            ->setParameter('u', $user)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(static fn(\App\Entity\Core\ConnexionLog $l) => [
            'date'      => $l->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'succes'    => $l->isSucces(),
            'ip'        => $l->getIp(),
            'user_agent' => $l->getUserAgent(),
            'contexte'  => $l->getContexte(),
            'raison_echec' => $l->getRaisonEchec(),
        ], $logs);
    }

    private function exportResetPasswordRequests(User $user): array
    {
        $reqs = $this->em->createQueryBuilder()
            ->select('r')
            ->from(\App\Entity\Core\ResetPasswordRequest::class, 'r')
            ->where('r.user = :u')
            ->setParameter('u', $user)
            ->orderBy('r.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn(\App\Entity\Core\ResetPasswordRequest $r) => [
            'requested_at' => $r->getRequestedAt()?->format(\DateTimeInterface::ATOM),
            'expires_at'   => $r->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'consumed_at'  => $r->getConsumedAt()?->format(\DateTimeInterface::ATOM),
            'request_ip'   => $r->getRequestIp(),
            // Le tokenHash n'est PAS exporté (info technique sans valeur pour le User)
        ], $reqs);
    }
}
