<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\CotisationJoueur;
use App\Entity\Sport\Subvention;
use App\Repository\Sport\SubventionRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\TresorerieVoter;
use App\Service\SubventionToucher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller des subventions — Bureau Phase D.4.
 *
 * Routes :
 *   GET  /tresorerie/subventions                       liste + KPIs par saison
 *   GET|POST /tresorerie/subventions/nouvelle          création
 *   GET|POST /tresorerie/subventions/{id}              édition (édit possible si pas finalisée)
 *   POST /tresorerie/subventions/{id}/transition       changer de statut
 *   POST /tresorerie/subventions/{id}/supprimer        delete (si pas TOUCHEE/REJETEE)
 *
 * Sécurité : TresorerieVoter — trésorier + super-admin uniquement.
 */
class SubventionController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly SubventionRepository $subventionRepository,
        private readonly EntityManagerInterface $em,
        private readonly SubventionToucher $toucher,
    ) {}

    #[Route('/tresorerie/subventions', name: 'manager_subventions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $club);

        $saison = (string) $request->query->get('saison', CotisationJoueur::getSaisonCourante());
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            $saison = CotisationJoueur::getSaisonCourante();
        }

        return $this->render('manager/subventions/index.html.twig', [
            'club'        => $club,
            'saison'      => $saison,
            'saisons'     => CotisationJoueur::getSaisonsRecentes(),
            'subventions' => $this->subventionRepository->findByClubAndSaison($club, $saison),
            'compteurs'   => $this->subventionRepository->countByStatutForClubAndSaison($club, $saison),
            'totaux'      => $this->subventionRepository->getTotauxForClubAndSaison($club, $saison),
        ]);
    }

    #[Route('/tresorerie/subventions/nouvelle', name: 'manager_subvention_new', methods: ['GET', 'POST'])]
    public function newSubvention(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $club);

        $sub = new Subvention();
        $sub->setClub($club);

        $errors = [];
        if ($request->isMethod('POST')) {
            $errors = $this->bind($request, $sub);
            if (empty($errors)) {
                $user = $this->getUser();
                if ($user instanceof User) {
                    $sub->setCreatedBy($user);
                }
                $this->em->persist($sub);
                $this->em->flush();
                $this->addFlash('success', sprintf('Subvention « %s » créée.', $sub->getIntitule()));
                return $this->redirectToRoute('manager_subvention_show', ['id' => $sub->getId()]);
            }
        }

        return $this->render('manager/subventions/form.html.twig', [
            'club'       => $club,
            'subvention' => $sub,
            'errors'     => $errors,
            'is_new'     => true,
        ]);
    }

    #[Route('/tresorerie/subventions/{id}', name: 'manager_subvention_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $sub = $this->subventionRepository->find($id);
        if (!$sub || $sub->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Subvention introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $sub);

        $errors = [];
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $sub);
            if ($sub->isFinalisee()) {
                $this->addFlash('error', 'Cette subvention est finalisée — modification impossible.');
                return $this->redirectToRoute('manager_subvention_show', ['id' => $sub->getId()]);
            }
            $errors = $this->bind($request, $sub);
            if (empty($errors)) {
                $this->em->flush();
                $this->addFlash('success', 'Subvention mise à jour.');
                return $this->redirectToRoute('manager_subvention_show', ['id' => $sub->getId()]);
            }
        }

        return $this->render('manager/subventions/form.html.twig', [
            'club'       => $club,
            'subvention' => $sub,
            'errors'     => $errors,
            'is_new'     => false,
        ]);
    }

    /**
     * Transition de statut.
     * Le body POST contient `target_statut` parmi STATUTS valides.
     *
     * Les transitions autorisées sont validées ici (machine à états simple).
     */
    #[Route('/tresorerie/subventions/{id}/transition', name: 'manager_subvention_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $sub = $this->subventionRepository->find($id);
        if (!$sub || $sub->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $sub);

        if (!$this->isCsrfTokenValid('transition_sub_' . $sub->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $target = (string) $request->request->get('target_statut', '');
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->appliquerTransition($sub, $target, $request, $user);
            $this->em->flush();
            $this->addFlash('success', sprintf('Subvention passée en « %s ».', $sub->getStatutLabel()));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Transition refusée : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_subvention_show', ['id' => $sub->getId()]);
    }

    #[Route('/tresorerie/subventions/{id}/supprimer', name: 'manager_subvention_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $sub = $this->subventionRepository->find($id);
        if (!$sub || $sub->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $sub);

        if (!$this->isCsrfTokenValid('delete_sub_' . $sub->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        // On refuse la suppression d'une subvention TOUCHEE (l'opération en
        // trésorerie y est liée par notes). REJETEE peut être supprimée
        // (pas d'impact compta).
        if ($sub->isTouchee()) {
            $this->addFlash('error', 'Impossible de supprimer une subvention déjà touchée. Crée une opération de remboursement séparée si nécessaire.');
            return $this->redirectToRoute('manager_subvention_show', ['id' => $sub->getId()]);
        }

        $intitule = $sub->getIntitule();
        $this->em->remove($sub);
        $this->em->flush();
        $this->addFlash('success', sprintf('Subvention « %s » supprimée.', $intitule));
        return $this->redirectToRoute('manager_subventions_index');
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Machine à états simple. Transitions autorisées :
     *   EN_PREPARATION → DEPOSEE
     *   DEPOSEE        → ACCORDEE | REJETEE
     *   ACCORDEE       → TOUCHEE
     *
     * TOUCHEE et REJETEE sont des états terminaux (figés).
     */
    private function appliquerTransition(Subvention $sub, string $target, Request $request, User $user): void
    {
        $current = $sub->getStatut();
        $transitionsValides = [
            Subvention::STATUT_EN_PREPARATION => [Subvention::STATUT_DEPOSEE],
            Subvention::STATUT_DEPOSEE        => [Subvention::STATUT_ACCORDEE, Subvention::STATUT_REJETEE],
            Subvention::STATUT_ACCORDEE       => [Subvention::STATUT_TOUCHEE],
        ];

        if (!isset($transitionsValides[$current]) || !in_array($target, $transitionsValides[$current], true)) {
            throw new \RuntimeException(sprintf(
                'Transition "%s → %s" non autorisée.',
                $current, $target
            ));
        }

        // Chaque transition demande des données spécifiques (dates, montants, motifs)
        switch ($target) {
            case Subvention::STATUT_DEPOSEE:
                // EN_PREPARATION → DEPOSEE : date de dépôt obligatoire
                try {
                    $sub->setDateDepot(new \DateTimeImmutable((string) $request->request->get('date_depot', 'now')));
                } catch (\Exception) {
                    throw new \RuntimeException('Date de dépôt invalide.');
                }
                $sub->setStatut(Subvention::STATUT_DEPOSEE);
                break;

            case Subvention::STATUT_ACCORDEE:
                // DEPOSEE → ACCORDEE : montant accordé obligatoire
                $m = trim((string) $request->request->get('montant_accorde', ''));
                $m = str_replace(',', '.', $m);
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $m) || (float) $m <= 0) {
                    throw new \InvalidArgumentException('Montant accordé invalide ou nul.');
                }
                $sub->setMontantAccorde(number_format((float) $m, 2, '.', ''));
                try {
                    $sub->setDateDecision(new \DateTimeImmutable((string) $request->request->get('date_decision', 'now')));
                } catch (\Exception) {
                    $sub->setDateDecision(new \DateTimeImmutable());
                }
                $sub->setStatut(Subvention::STATUT_ACCORDEE);
                break;

            case Subvention::STATUT_REJETEE:
                // DEPOSEE → REJETEE : motif optionnel mais recommandé
                $motif = trim((string) $request->request->get('motif_rejet', ''));
                $sub->setMotifRejet($motif !== '' ? $motif : null);
                try {
                    $sub->setDateDecision(new \DateTimeImmutable((string) $request->request->get('date_decision', 'now')));
                } catch (\Exception) {
                    $sub->setDateDecision(new \DateTimeImmutable());
                }
                $sub->setStatut(Subvention::STATUT_REJETEE);
                break;

            case Subvention::STATUT_TOUCHEE:
                // ACCORDEE → TOUCHEE : on délègue au service qui crée auto l'opération
                $m = trim((string) $request->request->get('montant_touche', $sub->getMontantAccorde() ?? '0'));
                $m = str_replace(',', '.', $m);
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $m) || (float) $m <= 0) {
                    throw new \InvalidArgumentException('Montant touché invalide.');
                }
                $m = number_format((float) $m, 2, '.', '');
                try {
                    $dateTouche = new \DateTimeImmutable((string) $request->request->get('date_touche', 'now'));
                } catch (\Exception) {
                    $dateTouche = new \DateTimeImmutable();
                }
                $this->toucher->marquerTouchee($sub, $m, $dateTouche, $user);
                break;
        }
    }

    /**
     * Binding form → entité pour création/édition simple.
     * @return string[] erreurs
     */
    private function bind(Request $request, Subvention $sub): array
    {
        $errors = [];

        $organisme = trim((string) $request->request->get('organisme', ''));
        if ($organisme === '') {
            $errors[] = 'Organisme obligatoire.';
        } else {
            $sub->setOrganisme($organisme);
        }

        $intitule = trim((string) $request->request->get('intitule', ''));
        if ($intitule === '') {
            $errors[] = 'Intitulé obligatoire.';
        } else {
            $sub->setIntitule($intitule);
        }

        $ref = trim((string) $request->request->get('reference_dossier', ''));
        $sub->setReferenceDossier($ref !== '' ? $ref : null);

        $lien = trim((string) $request->request->get('lien_dossier', ''));
        if ($lien !== '' && !preg_match('#^https?://#i', $lien)) {
            $errors[] = 'Lien dossier invalide (doit commencer par http:// ou https://).';
        }
        $sub->setLienDossier($lien !== '' ? $lien : null);

        $m = trim((string) $request->request->get('montant_demande', ''));
        $m = str_replace(',', '.', $m);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $m) || (float) $m <= 0) {
            $errors[] = 'Montant demandé invalide ou nul.';
        } else {
            $sub->setMontantDemande(number_format((float) $m, 2, '.', ''));
        }

        $saison = (string) $request->request->get('saison', CotisationJoueur::getSaisonCourante());
        try {
            $sub->setSaison($saison);
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        $notes = trim((string) $request->request->get('notes', ''));
        $sub->setNotes($notes !== '' ? $notes : null);

        return $errors;
    }
}
