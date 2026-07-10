<?php

declare(strict_types=1);

namespace App\Service\Secretariat;

use App\Entity\Core\User;
use App\Entity\Sport\DossierLicence;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\PreInscription;
use App\Entity\Sport\ResponsableLegal;
use App\Repository\Sport\DossierLicenceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ResponsableLegalRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Conversion d'une PreInscription en dossier concret [V2.4h].
 *
 * En UN clic de la secrétaire, la demande devient :
 *   1. un DossierLicence de la saison (upsert : si la joueuse a déjà un
 *      dossier cette saison, il est COMPLÉTÉ, jamais dupliqué) ;
 *   2. un ResponsableLegal rattaché à la fiche joueuse (si parent fourni
 *      et pas déjà connu — idempotent) ;
 *   3. optionnellement une fiche Joueur si elle n'existe pas.
 *
 * ANTI-DOUBLON (règle d'or Clavel) : le rapprochement avec les joueuses
 * DÉJÀ en base (saisons précédentes) se fait par nom+prénom normalisés
 * (NomOutil) AVANT toute création. `detecterJoueuse()` est exposée pour
 * que l'écran affiche « fiche existante détectée » AVANT la conversion.
 */
final class PreInscriptionConverter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JoueurRepository $joueurRepo,
        private readonly DossierLicenceRepository $dossierRepo,
        private readonly ResponsableLegalRepository $responsableRepo,
    ) {}

    /** Fiche Joueur existante correspondant à la pré-inscription (ou null). */
    public function detecterJoueuse(PreInscription $pre): ?Joueur
    {
        $club = $pre->getClub();
        if ($club === null) {
            return null;
        }
        $cible1 = NomOutil::normaliser(($pre->getNom() ?? '') . ' ' . ($pre->getPrenom() ?? ''));
        $cible2 = NomOutil::normaliser(($pre->getPrenom() ?? '') . ' ' . ($pre->getNom() ?? ''));
        foreach ($this->joueurRepo->findBy(['club' => $club, 'isActive' => true]) as $j) {
            $nomJ = NomOutil::normaliser(($j->getNom() ?? '') . ' ' . ($j->getPrenom() ?? ''));
            if ($nomJ === $cible1 || $nomJ === $cible2) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Convertit la pré-inscription. Flush inclus.
     *
     * @param bool        $creerFiche Créer la fiche Joueur si aucune existante
     * @param string|null $secteur    Secteur d'affectation (défaut : souhait famille)
     * @param string|null $categorie  Catégorie retenue (défaut : souhait famille)
     * @param string|null $tarif      Tarif de la licence (saisi par la secrétaire)
     *
     * @return DossierLicence Le dossier créé ou complété
     */
    public function convertir(
        PreInscription $pre,
        User $secretaire,
        bool $creerFiche,
        ?string $secteur = null,
        ?string $categorie = null,
        ?string $tarif = null,
    ): DossierLicence {
        if (!$pre->isNouvelle()) {
            throw new \RuntimeException('Cette pré-inscription a déjà été traitée.');
        }
        $club   = $pre->getClub() ?? throw new \RuntimeException('Pré-inscription sans club.');
        $saison = $pre->getSaison() ?? throw new \RuntimeException('Pré-inscription sans saison.');

        // 1. Fiche joueuse : existante (anti-doublon) ou créée si demandé
        $joueur = $this->detecterJoueuse($pre);
        if ($joueur === null && $creerFiche) {
            $joueur = new Joueur();
            $joueur->setClub($club);
            // Cast défensif : nom/prenom sont NOT NULL en base et validés au
            // dépôt, mais les getters sont typés ?string.
            $joueur->setNom((string) $pre->getNom());
            $joueur->setPrenom((string) $pre->getPrenom());
            $joueur->setDateNaissance($pre->getDateNaissance());
            $joueur->setTelephone($pre->getTelephoneJoueuse());
            $this->em->persist($joueur);
        }
        // Backfill des champs vides d'une fiche existante (jamais d'écrasement)
        if ($joueur !== null) {
            if ($joueur->getTelephone() === null && $pre->getTelephoneJoueuse() !== null) {
                $joueur->setTelephone($pre->getTelephoneJoueuse());
            }
            if ($joueur->getDateNaissance() === null && $pre->getDateNaissance() !== null) {
                $joueur->setDateNaissance($pre->getDateNaissance());
            }
        }

        // 2. Dossier licence : upsert (jamais deux dossiers pour la même
        //    joueuse sur la même saison)
        $nomComplet = mb_strtoupper((string) $pre->getNom()) . ' ' . (string) $pre->getPrenom();
        $dossier = $this->dossierRepo->trouverPourImport($club, $saison, null, $nomComplet);
        if ($dossier === null) {
            $dossier = new DossierLicence();
            $dossier->setClub($club)->setSaison($saison)->setNomComplet($nomComplet);
            $this->em->persist($dossier);
        }
        $dossier->setJoueur($dossier->getJoueur() ?? $joueur);
        $dossier->setSite($secteur ?: $pre->getSecteurSouhaite() ?: 'À placer');
        $dossier->setCategorie($categorie ?: $pre->getCategorie());
        if ($dossier->getDateNaissance() === null) { $dossier->setDateNaissance($pre->getDateNaissance()); }
        if ($dossier->getTelephone() === null)     { $dossier->setTelephone($pre->getTelephoneJoueuse()); }
        if ($tarif !== null && $tarif !== '')      { $dossier->setTarif($tarif); }
        $dossier->setNotes(trim(($dossier->getNotes() ?? '') . "\nIssue de la pré-inscription #" . $pre->getId() . ' du ' . $pre->getCreatedAt()?->format('d/m/Y')) ?: null);

        // 3. Contact parent (si fourni + fiche joueuse disponible + pas déjà connu)
        if ($joueur !== null && $pre->getParentNom() !== null
            && !$this->responsableRepo->existePour($joueur, $pre->getParentNom())) {
            $r = new ResponsableLegal();
            $r->setJoueur($joueur)
              ->setNomComplet($pre->getParentNom())
              ->setTelephone($pre->getParentTelephone())
              ->setEmail($pre->getParentEmail())
              ->setAdresse($pre->getParentAdresse())
              ->setCodePostal($pre->getParentCodePostal());
            $this->em->persist($r);
        }

        // 4. Clôture de la pré-inscription
        $pre->setStatut(PreInscription::STATUT_CONVERTIE);
        $pre->setTraiteLe(new \DateTimeImmutable());
        $pre->setTraitePar($secretaire);

        $this->em->flush();

        return $dossier;
    }
}
