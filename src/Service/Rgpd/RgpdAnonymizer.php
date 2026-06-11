<?php

declare(strict_types=1);

namespace App\Service\Rgpd;

use App\Entity\Core\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * B2 — RGPD : anonymisation d'un User (droit à l'oubli, Art. 17 RGPD).
 *
 * STRATÉGIE : on ANONYMISE, on ne SUPPRIME PAS.
 *
 * Pourquoi pas supprimer ?
 *   - Les présences, stats match, convocations, votes du User sont liées
 *     via FK. Supprimer le User casserait l'historique du club (stats
 *     d'une joueuse partie, vote MVP anonyme, comptabilité…).
 *   - Le User devient juridiquement non-identifiable : nom→Anonyme,
 *     email→deleted-{id}@anonyme.local, photo→null. Aucune donnée perso
 *     ne reste exploitable.
 *   - L'historique métier (présences, scores) est conservé sous forme
 *     pseudonymisée → conforme RGPD Art. 17 §3(d) "intérêt légitime de
 *     conservation à des fins d'archivage".
 *
 * Ce qui est anonymisé sur le User :
 *   - email   → "deleted-{id}@anonyme.local"
 *   - prenom  → "Anonyme"
 *   - nom     → "Utilisateur"
 *   - telephone → null
 *   - dateNaissance → null
 *   - photoPath → null (+ fichier physique supprimé)
 *   - bio → null
 *   - isActive → false (compte définitivement désactivé)
 *
 * Ce qui est SUPPRIMÉ (vrai DELETE) :
 *   - Toutes les UserClubRole de l'user (plus aucun rôle métier actif)
 *   - Les ResetPasswordRequest (B1) en attente
 *   - Les liens ParentJoueur (où l'user est parent)
 *
 * Ce qui est CONSERVÉ (pseudonymisé) :
 *   - User entity (PK gardée pour FK)
 *   - Toutes les FK qui pointent sur le User (présences, stats…)
 *   - Les ConnexionLog (audit légal — 12 mois max selon CNIL)
 */
class RgpdAnonymizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $uploadsRoot,
    ) {}

    /**
     * Anonymise l'utilisateur. Retourne l'ID anonymisé pour log.
     *
     * @return array{user_id: int, anonymized_email: string}
     */
    public function anonymizeUser(User $user): array
    {
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('User non persisté.');
        }

        $oldEmail = $user->getEmail();
        $newEmail = sprintf('deleted-%d@anonyme.local', $userId);

        // 1. Anonymisation des champs perso
        $user->setEmail($newEmail);
        $user->setPrenom('Anonyme');
        $user->setNom('Utilisateur');
        $user->setTelephone(null);
        $user->setDateNaissance(null);
        $user->setBio(null);

        // 2. Photo : suppression du fichier physique + path null
        $photoPath = $user->getPhotoPath();
        if ($photoPath !== null) {
            $absolute = rtrim($this->uploadsRoot, '/') . '/' . ltrim($photoPath, '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $user->setPhotoPath(null);
        }

        // 3. Désactivation définitive du compte
        $user->setIsActive(false);

        // 4. Suppression DURE des données liées :
        //    - UserClubRole (plus aucun rôle métier)
        //    - ResetPasswordRequest (token en attente)
        //    - ParentJoueur (liens parent)
        $this->em->createQuery('DELETE FROM App\Entity\Core\UserClubRole r WHERE r.user = :u')
            ->setParameter('u', $user)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Core\ResetPasswordRequest r WHERE r.user = :u')
            ->setParameter('u', $user)->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Sport\ParentJoueur p WHERE p.parentUser = :u')
            ->setParameter('u', $user)->execute();

        $this->em->flush();

        $this->logger->warning('Utilisateur anonymisé RGPD', [
            'user_id'    => $userId,
            'old_email_hash' => hash('sha256', $oldEmail ?? ''), // hash pour audit sans révéler
        ]);

        return [
            'user_id'           => $userId,
            'anonymized_email'  => $newEmail,
        ];
    }
}
