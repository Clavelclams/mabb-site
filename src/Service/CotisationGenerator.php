<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Sport\CotisationJoueur;
use App\Repository\Sport\CotisationJoueurRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\TarifCotisationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Génère en masse les cotisations pour une saison donnée — Bureau D.3 / D.3.1.
 *
 * PHASE D.3 (initial) — toutes les cotisations au même montant par défaut.
 *
 * PHASE D.3.1 (cette version) — utilise les TARIFS CONFIGURÉS par catégorie :
 *   1. Pour chaque joueur actif sans cotisation, on lit la catégorie de son équipe
 *   2. On cherche le tarif TarifCotisation (club, catégorie, saison)
 *   3. Si trouvé → on l'applique
 *   4. Sinon → fallback sur $montantDefaut (saisi dans le formulaire)
 *
 * Le RESULTAT est un compteur détaillé qui dit combien ont eu un tarif spécifique
 * et combien ont eu le fallback — utile pour signaler au trésorier qu'il manque
 * peut-être des tarifs à définir.
 */
final class CotisationGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JoueurRepository $joueurRepository,
        private readonly CotisationJoueurRepository $cotisationRepository,
        private readonly TarifCotisationRepository $tarifRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param Club   $club           Club concerné
     * @param string $saison         Format "YYYY-YYYY"
     * @param string $montantDefaut  Fallback si pas de tarif défini pour la catégorie
     *
     * @return array{nb_total: int, nb_avec_tarif: int, nb_avec_fallback: int, nb_sans_categorie: int}
     */
    public function generer(Club $club, string $saison, string $montantDefaut): array
    {
        $idsAGenerer = $this->cotisationRepository->findJoueursActifsSansCotisation($club, $saison);

        if (empty($idsAGenerer)) {
            $this->logger->info('Génération cotisations : aucune à créer', [
                'club_id' => $club->getId(),
                'saison'  => $saison,
            ]);
            return ['nb_total' => 0, 'nb_avec_tarif' => 0, 'nb_avec_fallback' => 0, 'nb_sans_categorie' => 0];
        }

        // Map catégorie → montant chargée UNE fois (O(1) lookup ensuite)
        $tarifsMap = $this->tarifRepository->getMapCategorieMontant($club, $saison);

        $nbAvecTarif      = 0;
        $nbAvecFallback   = 0;
        $nbSansCategorie  = 0;

        foreach ($idsAGenerer as $joueurId) {
            $joueur = $this->joueurRepository->find($joueurId);
            if (!$joueur) {
                continue;
            }

            // Récupère la catégorie via l'équipe (peut être null si joueur sans équipe)
            $categorie = $joueur->getEquipe()?->getCategorie();
            $montant   = $montantDefaut;

            if ($categorie !== null && isset($tarifsMap[$categorie])) {
                // Cas idéal : tarif défini pour cette catégorie/saison
                $montant = $tarifsMap[$categorie];
                $nbAvecTarif++;
            } elseif ($categorie === null) {
                // Cas dégradé : joueur sans équipe → fallback
                $nbSansCategorie++;
            } else {
                // Cas dégradé : catégorie connue mais pas de tarif → fallback
                $nbAvecFallback++;
            }

            $cotisation = new CotisationJoueur();
            $cotisation->setJoueur($joueur);
            $cotisation->setSaison($saison);
            $cotisation->setMontantAttendu($montant);
            $cotisation->setStatut(CotisationJoueur::STATUT_A_PAYER);
            $this->em->persist($cotisation);
        }

        $this->em->flush();

        $resultat = [
            'nb_total'          => $nbAvecTarif + $nbAvecFallback + $nbSansCategorie,
            'nb_avec_tarif'     => $nbAvecTarif,
            'nb_avec_fallback'  => $nbAvecFallback,
            'nb_sans_categorie' => $nbSansCategorie,
        ];

        $this->logger->info('Génération cotisations terminée', array_merge([
            'club_id' => $club->getId(),
            'saison'  => $saison,
        ], $resultat));

        return $resultat;
    }
}
