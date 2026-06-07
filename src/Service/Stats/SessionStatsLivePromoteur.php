<?php

declare(strict_types=1);

namespace App\Service\Stats;

use App\Entity\Core\User;
use App\Entity\Sport\Rencontre;
use App\Entity\Sport\SessionStatsLive;
use App\Repository\Sport\SessionStatsLiveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de promotion / archivage / création de SessionStatsLive — V2.1d Étape 2.
 *
 * RESPONSABILITÉS :
 *   - Créer / récupérer la session EN_COURS d'un user pour une rencontre
 *     (reprise après refresh tablette, multi-bénévoles parallèles).
 *   - Promouvoir une session en OFFICIELLE → les autres officielles
 *     repassent en ARCHIVEE (atomique, transactionnel).
 *   - Marquer une session COMPLETE quand le user termine sa saisie.
 *
 * GARANTIE : à un instant T, AU PLUS UNE session par rencontre est OFFICIELLE.
 * C'est garanti par la logique transactionnelle ici. La BDD ne le force pas
 * (pas de contrainte UNIQUE car ça impliquerait deferred constraints).
 *
 * Pattern aligné sur NoteFraisValidator + CotisationPayeur + SubventionToucher.
 */
final class SessionStatsLivePromoteur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionStatsLiveRepository $sessionRepository,
        private readonly LoggerInterface $logger,
        private readonly ActionMatchAggregator $aggregator,
    ) {}

    /**
     * Récupère la session EN_COURS du user pour cette rencontre, ou en crée une.
     *
     * Cas typiques :
     *   - 1er accès Stats Live → création (nom auto)
     *   - Reprise après refresh → on retrouve sa session
     *   - 2 bénévoles parallèles → chacun a sa session distincte
     */
    public function obtenirOuCreerSessionPourUser(
        Rencontre $rencontre,
        User $user,
    ): SessionStatsLive {
        // Cherche une session EN_COURS de ce user pour cette rencontre
        $session = $this->sessionRepository->findEnCoursForUserAndRencontre($user, $rencontre);
        if ($session !== null) {
            return $session;
        }

        // Création
        $session = new SessionStatsLive();
        $session->setRencontre($rencontre);
        $session->setCreatedBy($user);
        $session->setNom($this->genererNomAuto($user));
        $session->setStatut(SessionStatsLive::STATUT_EN_COURS);

        $this->em->persist($session);
        $this->em->flush();

        $this->logger->info('Session Stats Live créée', [
            'session_id'  => $session->getId(),
            'rencontre_id' => $rencontre->getId(),
            'user_id'      => $user->getId(),
        ]);

        return $session;
    }

    /**
     * Promeut une session en OFFICIELLE.
     * Transactionnel : si une autre session OFFICIELLE existe pour la même
     * rencontre, elle est rétrogradée en ARCHIVEE.
     *
     * @throws \RuntimeException si la session n'est pas dans un état promouvable
     */
    public function promouvoirOfficielle(SessionStatsLive $session, User $promoteur): void
    {
        // Une session EN_COURS peut être promue directement (le user n'a pas
        // forcément marqué COMPLETE — c'est OK, on accepte les 2 états).
        if ($session->isOfficielle()) {
            throw new \RuntimeException('Cette session est déjà officielle.');
        }
        if ($session->isArchivee()) {
            throw new \RuntimeException('Impossible de promouvoir une session archivée.');
        }

        $rencontre = $session->getRencontre();
        if ($rencontre === null) {
            throw new \RuntimeException('Session orpheline (sans rencontre).');
        }

        $this->em->beginTransaction();
        try {
            // 1. Trouve l'éventuelle OFFICIELLE existante et la rétrograde
            $existante = $this->sessionRepository->findOfficielleByRencontre($rencontre);
            if ($existante !== null && $existante->getId() !== $session->getId()) {
                $existante->setStatut(SessionStatsLive::STATUT_ARCHIVEE);
                $this->logger->info('Session précédemment officielle archivée', [
                    'ancienne_session_id' => $existante->getId(),
                ]);
            }

            // 2. Promeut la nouvelle
            $session->setStatut(SessionStatsLive::STATUT_OFFICIELLE);
            $session->setPromotedAt(new \DateTimeImmutable());
            $session->setPromotedBy($promoteur);

            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Session promue officielle', [
                'session_id'  => $session->getId(),
                'promoteur'   => $promoteur->getEmail(),
                'rencontre_id' => $rencontre->getId(),
            ]);

            // === V2.1d.1 — Auto-génération EvaluationMatch ===
            //
            // À la promotion, on régénère TOUS les EvaluationMatch des joueuses
            // qui ont des actions dans cette session officielle. Ainsi les
            // stats apparaissent immédiatement sur la fiche joueuse sans
            // intervention manuelle.
            //
            // En dehors de la transaction principale : on accepte que l'agrégation
            // puisse échouer sans annuler la promotion (qui est l'action critique
            // côté utilisateur). On log l'erreur si c'est le cas.
            try {
                $evals = $this->aggregator->regenererToutesPourRencontre($rencontre);
                $this->em->flush();
                $this->logger->info('EvaluationMatch auto-générées depuis Stats Live', [
                    'rencontre_id' => $rencontre->getId(),
                    'nb_evals'     => count($evals),
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Échec auto-génération EvaluationMatch (promotion OK quand même)', [
                    'rencontre_id' => $rencontre->getId(),
                    'error'        => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            $this->em->rollback();
            throw new \RuntimeException('Promotion échouée : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Marque une session COMPLETE — le user a fini sa saisie.
     * Elle reste visible pour qu'un staff puisse la promouvoir.
     */
    public function marquerComplete(SessionStatsLive $session): void
    {
        if (!$session->isEnCours()) {
            throw new \RuntimeException(sprintf(
                'Seule une session EN_COURS peut être marquée complète (statut actuel : %s).',
                $session->getStatut()
            ));
        }

        $session->setStatut(SessionStatsLive::STATUT_COMPLETE);
        $session->setCompletedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('Session marquée complète', [
            'session_id' => $session->getId(),
        ]);
    }

    /**
     * Archive une session (annulation par le staff).
     * Si la session est OFFICIELLE, elle redevient sans statut officiel
     * → la fiche joueuse n'a plus de stats agrégées pour cette rencontre
     * jusqu'à ce qu'une autre soit promue.
     */
    public function archiver(SessionStatsLive $session): void
    {
        if ($session->isArchivee()) {
            return; // idempotent
        }
        $session->setStatut(SessionStatsLive::STATUT_ARCHIVEE);
        $this->em->flush();

        $this->logger->info('Session archivée', [
            'session_id' => $session->getId(),
        ]);
    }

    /**
     * Nom auto suggéré pour une nouvelle session.
     * Format : "Sophie M. — 12/06 18h32"
     */
    private function genererNomAuto(User $user): string
    {
        $prenom = $user->getPrenom() ?? 'Anonyme';
        $nomInitiale = $user->getNom() ? mb_substr($user->getNom(), 0, 1) . '.' : '';
        return sprintf('%s %s — %s', $prenom, $nomInitiale, (new \DateTimeImmutable())->format('d/m H\\hi'));
    }
}
