<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Sport\CotisationJoueur;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\TarifCotisation;
use App\Repository\Sport\TarifCotisationRepository;
use App\Security\Tenant\TenantResolver;
use App\Security\Voter\TresorerieVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration des TARIFS de cotisation par catégorie d'âge — Bureau D.3.1.
 *
 * UI : un tableau de 12 catégories (Equipe::CATEGORIES) avec un montant
 * par ligne. Sauvegarde en BATCH (une seule soumission pour toutes les lignes).
 *
 * Le trésorier définit ces tarifs UNE FOIS par saison. Ensuite, lors de la
 * génération de cotisations, chaque joueuse hérite du tarif de sa catégorie.
 */
class TarifCotisationController extends AbstractController
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly TarifCotisationRepository $tarifRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Page de gestion des tarifs : tableau saisissable + dropdown saison.
     */
    #[Route('/tresorerie/tarifs', name: 'manager_tarifs_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $club = $this->tenantResolver->getCurrentClub();
        if (!$club) {
            throw $this->createNotFoundException('Aucun club actif.');
        }
        $this->denyAccessUnlessGranted(TresorerieVoter::CAN_MANAGE, $club);

        $saison = (string) ($request->isMethod('POST')
            ? $request->request->get('saison', CotisationJoueur::getSaisonCourante())
            : $request->query->get('saison', CotisationJoueur::getSaisonCourante()));

        if (!preg_match('/^\d{4}-\d{4}$/', $saison)) {
            $saison = CotisationJoueur::getSaisonCourante();
        }

        // === POST : sauvegarde en batch ===
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('save_tarifs', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $montants = $request->request->all('montants'); // ex: ['U13' => '180', 'U15' => '200']
            $nbModifs = $this->sauverTarifs($club, $saison, is_array($montants) ? $montants : []);

            if ($nbModifs > 0) {
                $this->addFlash('success', sprintf(
                    '%d tarif%s mis à jour pour la saison %s.',
                    $nbModifs, $nbModifs > 1 ? 's' : '', $saison
                ));
            } else {
                $this->addFlash('info', 'Aucune modification.');
            }
            return $this->redirectToRoute('manager_tarifs_index', ['saison' => $saison]);
        }

        // === GET : affichage ===
        // On construit la map catégorie → montant courant (ou 0.00 si non défini)
        $mapTarifs = $this->tarifRepository->getMapCategorieMontant($club, $saison);

        return $this->render('manager/tarifs/index.html.twig', [
            'club'       => $club,
            'saison'     => $saison,
            'saisons'    => CotisationJoueur::getSaisonsRecentes(),
            'categories' => Equipe::CATEGORIES,
            'tarifs_map' => $mapTarifs,
        ]);
    }

    /**
     * Sauvegarde en BATCH : pour chaque catégorie soumise, on crée ou met à
     * jour l'entité TarifCotisation. Si le montant est vide ou 0, on supprime
     * la ligne (pas de tarif défini = utiliser le fallback).
     *
     * @param array<string, string> $montants Map catégorie → montant saisi
     * @return int Nombre de lignes effectivement modifiées
     */
    private function sauverTarifs($club, string $saison, array $montants): int
    {
        $nbModifs = 0;

        foreach ($montants as $categorie => $montantBrut) {
            // Sécurité : refuser les catégories qui ne sont pas dans la whitelist
            if (!in_array($categorie, Equipe::CATEGORIES, true)) {
                continue;
            }

            $montantBrut = trim((string) $montantBrut);
            $montantBrut = str_replace(',', '.', $montantBrut);

            $tarif = $this->tarifRepository->findOneByClubCategorieSaison($club, $categorie, $saison);

            // Si vide ou 0 → on supprime le tarif (pas défini)
            if ($montantBrut === '' || $montantBrut === '0' || $montantBrut === '0.00') {
                if ($tarif !== null) {
                    $this->em->remove($tarif);
                    $nbModifs++;
                }
                continue;
            }

            // Validation format
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $montantBrut)) {
                continue;
            }
            $montantFormate = number_format((float) $montantBrut, 2, '.', '');

            if ($tarif === null) {
                // Création
                $tarif = new TarifCotisation();
                $tarif->setClub($club);
                $tarif->setCategorie($categorie);
                $tarif->setSaison($saison);
                $tarif->setMontant($montantFormate);
                $this->em->persist($tarif);
                $nbModifs++;
            } elseif ($tarif->getMontant() !== $montantFormate) {
                // Mise à jour si valeur différente
                $tarif->setMontant($montantFormate);
                $nbModifs++;
            }
        }

        if ($nbModifs > 0) {
            $this->em->flush();
        }
        return $nbModifs;
    }
}
