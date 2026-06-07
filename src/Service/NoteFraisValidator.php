<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\User;
use App\Entity\Sport\NoteFrais;
use App\Entity\Sport\OperationTresorerie;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de validation / rejet d'une note de frais — Bureau Phase D.2.
 *
 * Pourquoi un service à PART du controller ?
 *   - La validation est une OPÉRATION ATOMIQUE : on doit changer le statut
 *     de la note ET créer une OperationTresorerie de remboursement EN MÊME
 *     TEMPS. Si l'un échoue, l'autre ne doit pas exister (sinon la compta
 *     est incohérente).
 *   - On utilise une transaction Doctrine (begin / commit / rollback) →
 *     logique métier complexe qui n'a rien à faire dans le controller (SRP).
 *   - Testable indépendamment (PHPUnit futur : on mocke l'EM).
 *
 * Défense jury CDA : "j'ai isolé la logique transactionnelle dans un service
 * pour respecter SRP et garantir l'atomicité de l'opération métier".
 */
final class NoteFraisValidator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Valide une note de frais : passe en VALIDEE + crée auto l'opération
     * trésorerie de remboursement.
     *
     * @throws \RuntimeException si la note n'est pas EN_ATTENTE ou si la
     *                          transaction échoue.
     */
    public function valider(NoteFrais $note, User $validateur): OperationTresorerie
    {
        if (!$note->isEnAttente()) {
            throw new \RuntimeException(sprintf(
                'Impossible de valider une note au statut "%s". Seules les notes EN_ATTENTE peuvent être validées.',
                $note->getStatut()
            ));
        }

        $this->em->beginTransaction();
        try {
            // 1. Création de l'opération trésorerie liée
            $operation = new OperationTresorerie();
            $operation->setClub($note->getClub());
            $operation->setType(OperationTresorerie::TYPE_DEPENSE);
            $operation->setCategorie(OperationTresorerie::CAT_REMBOURSEMENTS);
            $operation->setMontant($note->getMontant());
            // Date de l'opération = date de la dépense engagée, pas la date de validation.
            // Pour le bilan annuel, c'est cohérent avec la réalité économique.
            $operation->setDate($note->getDateDepense());
            $operation->setLibelle(sprintf(
                'Remboursement note de frais — %s',
                $note->getLibelle()
            ));
            $operation->setNotes(sprintf(
                "Note de frais #%d validée le %s par %s.\nDemandeur initial : %s.",
                $note->getId(),
                (new \DateTimeImmutable())->format('d/m/Y H:i'),
                $validateur->getEmail() ?? '?',
                $note->getDemandeur()?->getEmail() ?? 'inconnu'
            ));
            $operation->setCreatedBy($validateur);
            $operation->setNoteFrais($note);

            $this->em->persist($operation);

            // 2. Mise à jour du statut de la note
            $note->setStatut(NoteFrais::STATUT_VALIDEE);
            $note->setValidateur($validateur);
            $note->setDateValidation(new \DateTimeImmutable());
            $note->setOperationTresorerie($operation);

            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Note de frais validée + opération créée', [
                'note_id'      => $note->getId(),
                'operation_id' => $operation->getId(),
                'validateur'   => $validateur->getEmail(),
                'montant'      => $note->getMontant(),
            ]);

            return $operation;
        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Échec validation note de frais', [
                'note_id' => $note->getId(),
                'error'   => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'Échec de la validation : ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Rejette une note de frais avec motif obligatoire.
     *
     * Pas de transaction nécessaire : on modifie juste l'entité.
     * Mais on log pour traçabilité.
     */
    public function rejeter(NoteFrais $note, User $validateur, string $motif): void
    {
        if (!$note->isEnAttente()) {
            throw new \RuntimeException(sprintf(
                'Impossible de rejeter une note au statut "%s".',
                $note->getStatut()
            ));
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new \InvalidArgumentException('Le motif de rejet est obligatoire.');
        }

        $note->setStatut(NoteFrais::STATUT_REJETEE);
        $note->setValidateur($validateur);
        $note->setDateValidation(new \DateTimeImmutable());
        $note->setMotifRejet($motif);

        $this->em->flush();

        $this->logger->info('Note de frais rejetée', [
            'note_id'    => $note->getId(),
            'validateur' => $validateur->getEmail(),
            'motif'      => $motif,
        ]);
    }
}
