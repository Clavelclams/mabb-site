<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\CotisationJoueur;
use App\Repository\Sport\CotisationJoueurRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\TresorerieVoter;
use App\Service\CotisationGenerator;
use App\Service\CotisationPayeur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller des cotisations licenciées — Bureau Phase D.3.
 *
 * Routé sur manager.mabb.fr, protégé par TresorerieVoter
 * (trésorier ou super-admin uniquement — ce sont des données financières).
 */
class CotisationController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly CotisationJoueurRepository $cotisationRepository,
        private readonly EntityManagerInterface $em,
        private readonly CotisationGenerator $generator,
        private readonly CotisationPayeur $payeur,
    ) {}

    /**
     * Vue d'ensemble des cotisations pour une saison.
     * Dropdown pour changer de saison.
     */
    #[Route('/tresorerie/cotisations', name: 'manager_cotisations_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $club);

        // Saison demandée via querystring, défaut = courante
        $saison = (string) $request->query->get('saison', CotisationJoueur::getSaisonCourante());
        // Sécurité : refuser tout format hors "YYYY-YYYY"
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            $saison = CotisationJoueur::getSaisonCourante();
        }

        $cotisations = $this->cotisationRepository->findByClubAndSaison($club, $saison);
        $compteurs   = $this->cotisationRepository->countByStatutForClubAndSaison($club, $saison);
        $totaux      = $this->cotisationRepository->getTotauxForClubAndSaison($club, $saison);

        // Calcul du taux de paiement (utile pour la barre de progression)
        $tauxPaiement = 0;
        if (bccomp($totaux['attendu'], '0', 2) > 0) {
            $tauxPaiement = (int) round(((float) $totaux['percu'] / (float) $totaux['attendu']) * 100);
        }

        return $this->render('manager/cotisations/index.html.twig', [
            'club'             => $club,
            'saison'           => $saison,
            'saisons'          => CotisationJoueur::getSaisonsRecentes(),
            'cotisations'      => $cotisations,
            'compteurs'        => $compteurs,
            'totaux'           => $totaux,
            'taux_paiement'    => $tauxPaiement,
        ]);
    }

    /**
     * Génération en masse des cotisations pour la saison.
     * Lance le service generator → crée les lignes manquantes pour les
     * joueurs actifs.
     */
    #[Route('/tresorerie/cotisations/generer', name: 'manager_cotisations_generer', methods: ['POST'])]
    public function generer(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $club);

        if (!$this->isCsrfTokenValid('generer_cotisations', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $saison = (string) $request->request->get('saison', CotisationJoueur::getSaisonCourante());
        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            $this->addFlash('error', 'Saison invalide.');
            return $this->redirectToRoute('manager_cotisations_index');
        }

        $montantDefaut = trim((string) $request->request->get('montant_defaut', '0'));
        $montantDefaut = str_replace(',', '.', $montantDefaut);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $montantDefaut)) {
            $this->addFlash('error', 'Montant par défaut invalide.');
            return $this->redirectToRoute('manager_cotisations_index', ['saison' => $saison]);
        }
        $montantDefaut = number_format((float) $montantDefaut, 2, '.', '');

        try {
            $stats = $this->generator->generer($club, $saison, $montantDefaut);
            if ($stats['nb_total'] === 0) {
                $this->addFlash('info', sprintf('Aucune cotisation à créer pour %s — tout est à jour.', $saison));
            } else {
                $this->addFlash('success', sprintf(
                    '%d cotisation%s créée%s pour la saison %s.',
                    $stats['nb_total'],
                    $stats['nb_total'] > 1 ? 's' : '',
                    $stats['nb_total'] > 1 ? 's' : '',
                    $saison
                ));
                // Warnings utiles pour le trésorier — il saura quoi affiner ensuite
                if ($stats['nb_avec_fallback'] > 0) {
                    $this->addFlash('warning', sprintf(
                        '%d joueuse%s sans tarif défini pour sa catégorie — montant par défaut (%s €) appliqué. '
                        . 'Pense à définir les tarifs manquants dans la page "Tarifs cotisations".',
                        $stats['nb_avec_fallback'],
                        $stats['nb_avec_fallback'] > 1 ? 's' : '',
                        $montantDefaut
                    ));
                }
                if ($stats['nb_sans_categorie'] > 0) {
                    $this->addFlash('warning', sprintf(
                        '%d joueuse%s sans équipe rattachée — montant par défaut appliqué.',
                        $stats['nb_sans_categorie'],
                        $stats['nb_sans_categorie'] > 1 ? 's' : ''
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Génération échouée : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_cotisations_index', ['saison' => $saison]);
    }

    /**
     * Détail + actions sur UNE cotisation (modifier montant, payer, exempter).
     */
    #[Route('/tresorerie/cotisations/{id}', name: 'manager_cotisation_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }

        $cotisation = $this->cotisationRepository->find($id);
        if (!$cotisation || $cotisation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Cotisation introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_VIEW, $cotisation);

        // POST = modification du montant attendu ou des notes
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $cotisation);
            $this->traiterEditionMontant($request, $cotisation);
            return $this->redirectToRoute('manager_cotisation_show', ['id' => $cotisation->getId()]);
        }

        return $this->render('manager/cotisations/show.html.twig', [
            'club'       => $club,
            'cotisation' => $cotisation,
        ]);
    }

    /**
     * Action : marquer une cotisation comme payée intégralement.
     * Crée auto une OperationTresorerie RECETTE catégorie COTISATIONS
     * pour le montant restant dû.
     */
    #[Route('/tresorerie/cotisations/{id}/payer', name: 'manager_cotisation_payer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function payer(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $cotisation = $this->cotisationRepository->find($id);
        if (!$cotisation || $cotisation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Cotisation introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $cotisation);

        if (!$this->isCsrfTokenValid('payer_cotisation_' . $cotisation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $tresorier = $this->getUser();
        if (!$tresorier instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Date de paiement : valeur du form ou aujourd'hui par défaut
        try {
            $datePaiement = new \DateTimeImmutable((string) $request->request->get('date_paiement', 'now'));
        } catch (\Exception) {
            $datePaiement = new \DateTimeImmutable();
        }

        try {
            $this->payeur->payerIntegralement($cotisation, $tresorier, $datePaiement);
            $this->addFlash('success', sprintf(
                'Cotisation de %s %s marquée payée — recette enregistrée.',
                $cotisation->getJoueur()?->getPrenom() ?? '?',
                $cotisation->getJoueur()?->getNom() ?? '?'
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Paiement échoué : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_cotisation_show', ['id' => $cotisation->getId()]);
    }

    /**
     * Action : enregistrer un versement partiel (échéancier).
     */
    #[Route('/tresorerie/cotisations/{id}/versement', name: 'manager_cotisation_versement', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function versement(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $cotisation = $this->cotisationRepository->find($id);
        if (!$cotisation || $cotisation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Cotisation introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $cotisation);

        if (!$this->isCsrfTokenValid('versement_cotisation_' . $cotisation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $tresorier = $this->getUser();
        if (!$tresorier instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $montant = trim((string) $request->request->get('montant_versement', ''));
        $montant = str_replace(',', '.', $montant);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $montant)) {
            $this->addFlash('error', 'Montant du versement invalide.');
            return $this->redirectToRoute('manager_cotisation_show', ['id' => $cotisation->getId()]);
        }
        $montant = number_format((float) $montant, 2, '.', '');

        try {
            $dateVersement = new \DateTimeImmutable((string) $request->request->get('date_versement', 'now'));
        } catch (\Exception) {
            $dateVersement = new \DateTimeImmutable();
        }

        try {
            $this->payeur->enregistrerVersement($cotisation, $montant, $tresorier, $dateVersement);
            $this->addFlash('success', sprintf('Versement de %s € enregistré.', $montant));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Versement échoué : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_cotisation_show', ['id' => $cotisation->getId()]);
    }

    /**
     * Action : exempter une cotisation (motif obligatoire).
     */
    #[Route('/tresorerie/cotisations/{id}/exempter', name: 'manager_cotisation_exempter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function exempter(int $id, Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $cotisation = $this->cotisationRepository->find($id);
        if (!$cotisation || $cotisation->getClub()?->getId() !== $club->getId()) {
            throw $this->createNotFoundException('Cotisation introuvable.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $cotisation);

        if (!$this->isCsrfTokenValid('exempter_cotisation_' . $cotisation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $motif = trim((string) $request->request->get('motif', ''));
        try {
            $this->payeur->exempter($cotisation, $motif);
            $this->addFlash('success', 'Cotisation exemptée.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Exemption refusée : ' . $e->getMessage());
        }

        return $this->redirectToRoute('manager_cotisation_show', ['id' => $cotisation->getId()]);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Édition simple du montant attendu et des notes — pas d'impact sur
     * la trésorerie tant que la cotisation n'est pas payée.
     */
    private function traiterEditionMontant(Request $request, CotisationJoueur $cotisation): void
    {
        if ($cotisation->isPayee()) {
            $this->addFlash('error', 'Impossible de modifier une cotisation déjà payée. Créer un avoir si nécessaire.');
            return;
        }

        $montant = trim((string) $request->request->get('montant_attendu', ''));
        $montant = str_replace(',', '.', $montant);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $montant)) {
            $cotisation->setMontantAttendu(number_format((float) $montant, 2, '.', ''));
        }

        $notes = trim((string) $request->request->get('notes', ''));
        $cotisation->setNotes($notes !== '' ? $notes : null);

        $this->em->flush();
        $this->addFlash('success', 'Cotisation mise à jour.');
    }
}
