<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\User;
use App\Entity\Sport\CotisationJoueur;
use App\Entity\Sport\OperationTresorerie;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des paiements de cotisations — Bureau Phase D.3.
 *
 * Workflow atomique : changer le statut d'une cotisation + créer
 * l'OperationTresorerie RECETTE correspondante doivent réussir ENSEMBLE
 * ou rien du tout. Sinon la trésorerie devient incohérente avec le suivi
 * des cotisations.
 *
 * SRP : la logique métier transactionnelle est ICI, pas dans le controller.
 * Pattern identique à NoteFraisValidator (Phase D.2) — cohérence du codebase.
 */
final class CotisationPayeur
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Marque une cotisation comme PAYEE intégralement.
     * Crée l'opération RECETTE catégorie COTISATIONS pour le montant restant
     * dû (utile si on payait déjà partiellement en échéancier).
     *
     * @throws \RuntimeException si la cotisation est déjà PAYEE ou EXEMPTEE
     */
    public function payerIntegralement(
        CotisationJoueur $cotisation,
        User $tresorier,
        \DateTimeImmutable $datePaiement,
    ): OperationTresorerie {
        if ($cotisation->isPayee()) {
            throw new \RuntimeException('Cette cotisation est déjà payée.');
        }
        if ($cotisation->isExemptee()) {
            throw new \RuntimeException('Cette cotisation est exemptée — impossible de la marquer comme payée.');
        }

        // Montant à enregistrer = solde restant (peut être < montantAttendu si
        // on était en échéancier avec déjà des versements).
        $montantRestant = $cotisation->getMontantRestant();
        if (bccomp($montantRestant, '0', 2) <= 0) {
            throw new \RuntimeException('Aucun montant restant à payer.');
        }

        $club = $cotisation->getClub();
        if ($club === null) {
            throw new \RuntimeException('La cotisation n\'a pas de club rattaché (joueur orphelin ?).');
        }

        $this->em->beginTransaction();
        try {
            // 1. Création opération RECETTE
            $operation = new OperationTresorerie();
            $operation->setClub($club);
            $operation->setType(OperationTresorerie::TYPE_RECETTE);
            $operation->setCategorie(OperationTresorerie::CAT_COTISATIONS);
            $operation->setMontant($montantRestant);
            $operation->setDate($datePaiement);
            $operation->setLibelle(sprintf(
                'Cotisation %s — %s %s',
                $cotisation->getSaison(),
                $cotisation->getJoueur()?->getPrenom() ?? '?',
                $cotisation->getJoueur()?->getNom() ?? '?'
            ));
            $operation->setNotes(sprintf(
                "Paiement enregistré le %s par %s.\nCotisation joueuse #%d, saison %s.",
                (new \DateTimeImmutable())->format('d/m/Y H:i'),
                $tresorier->getEmail() ?? '?',
                $cotisation->getId(),
                $cotisation->getSaison()
            ));
            $operation->setCreatedBy($tresorier);
            $this->em->persist($operation);

            // 2. MAJ cotisation
            $cotisation->setStatut(CotisationJoueur::STATUT_PAYEE);
            $cotisation->setMontantPaye($cotisation->getMontantAttendu());
            $cotisation->setDatePaiement($datePaiement);

            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Cotisation marquée payée + opération créée', [
                'cotisation_id' => $cotisation->getId(),
                'operation_id'  => $operation->getId(),
                'joueur_id'     => $cotisation->getJoueur()?->getId(),
                'saison'        => $cotisation->getSaison(),
                'montant'       => $montantRestant,
            ]);

            return $operation;
        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Échec paiement cotisation', [
                'cotisation_id' => $cotisation->getId(),
                'error'         => $e->getMessage(),
            ]);
            throw new \RuntimeException('Échec du paiement : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Enregistre un versement partiel (échéancier).
     * Passe en statut ECHEANCIER. Crée aussi une opération RECETTE pour le versement.
     *
     * Note : on ne lie PAS l'opération à la cotisation côté entité — c'est un
     * versement parmi d'autres. La traçabilité passe par les notes.
     *
     * @throws \RuntimeException si versement >= reste dû (utiliser payerIntegralement)
     */
    public function enregistrerVersement(
        CotisationJoueur $cotisation,
        string $montantVersement,
        User $tresorier,
        \DateTimeImmutable $dateVersement,
    ): OperationTresorerie {
        if ($cotisation->isPayee() || $cotisation->isExemptee()) {
            throw new \RuntimeException('Cotisation déjà finalisée — pas de nouveau versement possible.');
        }
        if (str_starts_with($montantVersement, '-') || bccomp($montantVersement, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Le montant du versement doit être strictement positif.');
        }

        // Si le versement couvre TOUT le restant → bascule vers payerIntegralement
        $restant = $cotisation->getMontantRestant();
        if (bccomp($montantVersement, $restant, 2) >= 0) {
            return $this->payerIntegralement($cotisation, $tresorier, $dateVersement);
        }

        $club = $cotisation->getClub();
        if ($club === null) {
            throw new \RuntimeException('Cotisation sans club.');
        }

        $this->em->beginTransaction();
        try {
            $operation = new OperationTresorerie();
            $operation->setClub($club);
            $operation->setType(OperationTresorerie::TYPE_RECETTE);
            $operation->setCategorie(OperationTresorerie::CAT_COTISATIONS);
            $operation->setMontant($montantVersement);
            $operation->setDate($dateVersement);
            $operation->setLibelle(sprintf(
                'Versement cotisation %s — %s %s',
                $cotisation->getSaison(),
                $cotisation->getJoueur()?->getPrenom() ?? '?',
                $cotisation->getJoueur()?->getNom() ?? '?'
            ));
            $operation->setNotes(sprintf(
                "Versement partiel (échéancier) — cotisation #%d, saison %s. Saisi par %s.",
                $cotisation->getId(),
                $cotisation->getSaison(),
                $tresorier->getEmail() ?? '?'
            ));
            $operation->setCreatedBy($tresorier);
            $this->em->persist($operation);

            // MAJ cotisation : ajouter le versement au cumul, passer en ECHEANCIER
            $nouveauPaye = bcadd($cotisation->getMontantPaye(), $montantVersement, 2);
            $cotisation->setMontantPaye($nouveauPaye);
            $cotisation->setStatut(CotisationJoueur::STATUT_ECHEANCIER);

            $this->em->flush();
            $this->em->commit();

            $this->logger->info('Versement cotisation enregistré', [
                'cotisation_id' => $cotisation->getId(),
                'operation_id'  => $operation->getId(),
                'versement'     => $montantVersement,
                'nouveau_paye'  => $nouveauPaye,
            ]);

            return $operation;
        } catch (\Exception $e) {
            $this->em->rollback();
            $this->logger->error('Échec versement', [
                'cotisation_id' => $cotisation->getId(),
                'error'         => $e->getMessage(),
            ]);
            throw new \RuntimeException('Échec du versement : ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exempte une cotisation (pas de paiement attendu).
     * Cas typiques : service civique, dirigeant en contrepartie de bénévolat,
     * difficultés financières acceptées par le bureau, etc.
     *
     * Pas de transaction nécessaire : on ne touche pas à la trésorerie.
     *
     * @throws \InvalidArgumentException si motif vide
     * @throws \RuntimeException si cotisation déjà payée ou échéancier en cours
     */
    public function exempter(CotisationJoueur $cotisation, string $motif): void
    {
        if ($cotisation->isPayee()) {
            throw new \RuntimeException('Cotisation déjà payée — impossible d\'exempter.');
        }
        if ($cotisation->isEcheancier() && bccomp($cotisation->getMontantPaye(), '0', 2) > 0) {
            throw new \RuntimeException(
                'Des versements ont déjà été reçus — impossible d\'exempter. '
                . 'Si nécessaire, créer une opération de remboursement séparée.'
            );
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new \InvalidArgumentException('Le motif d\'exemption est obligatoire.');
        }

        $cotisation->setStatut(CotisationJoueur::STATUT_EXEMPTEE);
        $cotisation->setMotifExemption($motif);
        // Mettre le montant attendu à 0 pour qu'il ne pèse plus sur le "reste à percevoir"
        // mais on garde la valeur originale dans les notes pour audit.
        $cotisation->setNotes(sprintf(
            "%sExemptée le %s — montant initialement attendu : %s €.",
            $cotisation->getNotes() ? $cotisation->getNotes() . "\n" : '',
            (new \DateTimeImmutable())->format('d/m/Y'),
            $cotisation->getMontantAttendu()
        ));

        $this->em->flush();

        $this->logger->info('Cotisation exemptée', [
            'cotisation_id' => $cotisation->getId(),
            'motif'         => $motif,
        ]);
    }
}
