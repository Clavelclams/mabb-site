<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\User;
use App\Entity\Sport\OperationTresorerie;
use App\Entity\Sport\Subvention;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de finalisation d'une subvention "touchée" — Bureau D.4.
 *
 * Workflow atomique : marquer la subvention TOUCHEE + créer l'OperationTresorerie
 * RECETTE catégorie SUBVENTIONS. Soit les deux réussissent, soit aucun.
 *
 * Pattern aligné sur NoteFraisValidator + CotisationPayeur (cohérence projet).
 * Défense jury CDA : SRP + transactionnalité.
 */
final class SubventionToucher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Marque une subvention comme TOUCHEE et crée auto l'opération recette.
     *
     * @throws \RuntimeException si la subvention n'est pas ACCORDEE
     */
    public function marquerTouchee(
        Subvention $subvention,
        string $montantTouche,
        \DateTimeImmutable $dateTouche,
        User $tresorier,
    ): OperationTresorerie {
        if (!$subvention->isAccordee()) {
            throw new \RuntimeException(sprintf(
                'Seule une subvention ACCORDEE peut être marquée touchée (statut actuel : %s).',
                $subvention->getStatut()
            ));
        }

        if (str_starts_with($montantTouche, '-') || bccomp($montantTouche, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Le montant touché doit être strictement positif.');
        }

        $club = $subvention->getClub();
        if ($club === null) {
            throw new \RuntimeException('Subvention sans club rattaché.');
        }

        $this->em->beginTransaction();
        try {
            // 1. Création opération RECETTE catégorie SUBVENTIONS
            $operation = new OperationTresorerie();
            $operation->setClub($club);
            $operation->setType(OperationTresorerie::TYPE_RECETTE);
            $operation->setCategorie(OperationTresorerie::CAT_SUBVENTIONS);
            $operation->setMontant($montantTouche);
            $operation->setDate($dateTouche);
            $operation->setLibelle(sprintf(
                'Subvention %s — %s',
                $subvention->getOrganisme(),
                $subvention->getIntitule()
            ));
            $operation->setNotes(sprintf(
                "Subvention #%d (%s) versée le %s.\nDemandée : %s €, accordée : %s €, touchée : %s €.\nSaisi par %s.",
                $subvention->getId(),
                $subvention->getSaison(),
                $dateTouche->format('d/m/Y'),
                $subvention->getMontantDemande(),
                $subvention->getMontantAccorde() ?? '?',
                $montantTouche,
                $tresorier->getEmail() ?? '?'
            ));
            $operation->setCreatedBy($tresorier);
            $this->em->persist($operation);
            $this->em->flush(); // pour avoir l'ID de l'opération

            // 2. MAJ subvention
            $subvention->setStatut(Subvention::STATUT_TOUCHEE);
            $subvention->setMontantTouche($montantTouche);
            $subvention->setDateTouche($dateTouche);
            $subvention->setOperationTresorerieId($operation->getId());

            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Subvention touchée + opération créée', [
                'subvention_id' => $subvention->getId(),
                'operation_id'  => $operation->getId(),
                'organisme'     => $subvention->getOrganisme(),
                'montant'       => $montantTouche,
            ]);

            return $operation;
        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Échec finalisation subvention', [
                'subvention_id' => $subvention->getId(),
                'error'         => $e->getMessage(),
            ]);
            throw new \RuntimeException('Échec : ' . $e->getMessage(), 0, $e);
        }
    }
}
