<?php

declare(strict_types=1);

namespace App\Service\Secretariat;

use App\Entity\Core\Club;
use App\Entity\Sport\DossierLicence;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\ResponsableLegal;
use App\Repository\Sport\DossierLicenceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ResponsableLegalRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsxDate;

/**
 * Imports Excel du secrétariat [V2.4g 09/07/2026].
 *
 * Deux formats reconnus (fichiers réels de la secrétaire MABB) :
 *
 * 1. « LICENCIÉS <SITE> <SAISON>.xlsx » — un ONGLET par catégorie, colonnes :
 *    Types de Licences | N° Licences | Noms / Prénoms | Dates de naissance |
 *    N° téléphone | Tarifs | Aides Mairie | PASS | Chèques Collège |
 *    Chèques | Espèces | Paiement
 *    → upsert de DossierLicence (idempotent : n° licence prioritaire,
 *      sinon nom+saison). RIEN n'est perdu : les colonnes d'aides partent
 *      brutes dans le JSON `aides`.
 *
 * 2. « Organisation match », onglet « Formulaire Brut licence » (Google Form) :
 *    Horodateur | Date de naissance | nom | prénom | catégorie | Numéro de
 *    tel joueuse | Nom Prénom Parents | Adresse | Code postal | Mail … |
 *    Ton responsable | Téléphones parents
 *    → création de ResponsableLegal rattachés aux fiches Joueur (match par
 *      nom+prénom normalisés). Les non-matchées sont listées dans le
 *      rapport — AUCUNE donnée n'est jetée silencieusement.
 *
 * Le service ne flush QUE si $dryRun est false. Aucune vérification de
 * permission ici : le contrôleur doit vérifier CLUB_SECRETARIAT avant.
 */
final class SecretariatImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DossierLicenceRepository $dossierRepo,
        private readonly ResponsableLegalRepository $responsableRepo,
        private readonly JoueurRepository $joueurRepo,
    ) {}

    /**
     * Import d'un fichier « LICENCIÉS <SITE> ».
     *
     * @return array{crees:int, maj:int, ignorees:int, erreurs:string[], onglets:string[]}
     */
    public function importLicencies(string $cheminXlsx, Club $club, string $saison, string $site, bool $dryRun): array
    {
        $rapport = ['crees' => 0, 'maj' => 0, 'ignorees' => 0, 'erreurs' => [], 'onglets' => []];

        $spreadsheet = IOFactory::load($cheminXlsx);
        $joueusesParNom = $this->indexJoueusesParNom($club);

        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            $categorie = trim($ws->getTitle());
            $rows = $ws->toArray(null, true, false, false); // valeurs calculées, index 0
            if ($rows === [] || !$this->estOngletLicencies($rows[0] ?? [])) {
                $rapport['erreurs'][] = "Onglet « {$categorie} » ignoré (en-têtes non reconnues).";
                continue;
            }
            $rapport['onglets'][] = $categorie;

            foreach (array_slice($rows, 1) as $i => $row) {
                $nom = trim((string) ($row[2] ?? ''));
                if ($nom === '') {
                    continue; // ligne vide
                }
                try {
                    $numero  = trim((string) ($row[1] ?? ''));
                    $dossier = $this->dossierRepo->trouverPourImport($club, $saison, $numero !== '' ? strtoupper($numero) : null, $nom);
                    $nouveau = ($dossier === null);
                    if ($nouveau) {
                        $dossier = new DossierLicence();
                        $dossier->setClub($club)->setSaison($saison);
                    }

                    $dossier->setSite($site)
                        ->setCategorie($categorie)
                        ->setTypeLicence($this->normaliserType((string) ($row[0] ?? '')))
                        ->setNumeroLicence($numero ?: null)
                        ->setNomComplet($nom)
                        ->setDateNaissance($this->lireDate($row[3] ?? null))
                        ->setTelephone(trim((string) ($row[4] ?? '')) ?: null)
                        ->setTarif(trim((string) ($row[5] ?? '')) ?: null);

                    $aides = array_filter([
                        'aide_mairie'     => trim((string) ($row[6] ?? '')),
                        'pass'            => trim((string) ($row[7] ?? '')),
                        'cheques_college' => trim((string) ($row[8] ?? '')),
                        'cheques'         => trim((string) ($row[9] ?? '')),
                        'especes'         => trim((string) ($row[10] ?? '')),
                    ], fn(string $v) => $v !== '');
                    $dossier->setAides($aides ?: null);
                    $dossier->setPaiementStatut($this->deduireStatutPaiement(
                        trim((string) ($row[11] ?? '')),
                        (string) $dossier->getTarif(),
                        $aides !== []
                    ));

                    // Lien vers la fiche Joueur si elle existe (facultatif)
                    if ($dossier->getJoueur() === null) {
                        $dossier->setJoueur($joueusesParNom[$this->normaliserNom($nom)] ?? null);
                    }

                    if ($nouveau) {
                        if (!$dryRun) { $this->em->persist($dossier); }
                        $rapport['crees']++;
                    } else {
                        $rapport['maj']++;
                    }
                } catch (\Throwable $e) {
                    $rapport['erreurs'][] = sprintf('%s L%d (%s) : %s', $categorie, $i + 2, $nom, $e->getMessage());
                    $rapport['ignorees']++;
                }
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        return $rapport;
    }

    /**
     * Import des contacts parents depuis le « Formulaire Brut licence ».
     *
     * @return array{crees:int, doublons:int, non_matchees:string[], erreurs:string[]}
     */
    public function importParents(string $cheminXlsx, Club $club, bool $dryRun): array
    {
        $rapport = ['crees' => 0, 'doublons' => 0, 'non_matchees' => [], 'erreurs' => []];

        $spreadsheet = IOFactory::load($cheminXlsx);
        $joueusesParNom = $this->indexJoueusesParNom($club);

        // Cherche l'onglet du formulaire (en-tête contenant « Parents »)
        $feuille = null;
        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            $entetes = implode('|', array_map(strval(...), $ws->toArray(null, true, false, false)[0] ?? []));
            if (stripos($entetes, 'Parents') !== false && stripos($entetes, 'nom') !== false) {
                $feuille = $ws;
                break;
            }
        }
        if ($feuille === null) {
            $rapport['erreurs'][] = 'Aucun onglet « Formulaire Brut licence » reconnu (colonne Parents introuvable).';
            return $rapport;
        }

        $rows = $feuille->toArray(null, true, false, false);
        foreach (array_slice($rows, 1) as $i => $row) {
            // Colonnes : 1=naissance 2=nom 3=prénom 6=Nom Prénom Parents
            // 7=Adresse 8=CP 9=Mail 11=Téléphones parents
            $nom    = trim((string) ($row[2] ?? ''));
            $prenom = trim((string) ($row[3] ?? ''));
            if ($nom === '' && $prenom === '') {
                continue;
            }
            $joueur = $joueusesParNom[$this->normaliserNom($nom . ' ' . $prenom)]
                   ?? $joueusesParNom[$this->normaliserNom($prenom . ' ' . $nom)]
                   ?? null;
            if (!$joueur instanceof Joueur) {
                $rapport['non_matchees'][] = trim($prenom . ' ' . $nom) . ' (L' . ($i + 2) . ')';
                continue;
            }

            $nomParents = trim((string) ($row[6] ?? ''));
            if ($nomParents === '') {
                continue; // pas de contact parent sur cette ligne
            }
            if ($this->responsableRepo->existePour($joueur, $nomParents)) {
                $rapport['doublons']++;
                continue;
            }

            $r = new ResponsableLegal();
            $r->setJoueur($joueur)
              ->setNomComplet($nomParents)
              ->setTelephone(trim((string) ($row[11] ?? '')) ?: null)
              ->setEmail(trim((string) ($row[9] ?? '')) ?: null)
              ->setAdresse(trim((string) ($row[7] ?? '')) ?: null)
              ->setCodePostal($this->lireCodePostal($row[8] ?? null));
            if (!$dryRun) {
                $this->em->persist($r);
            }
            $rapport['crees']++;
        }

        if (!$dryRun) {
            $this->em->flush();
        }
        return $rapport;
    }

    // ====================================================================
    // Helpers privés
    // ====================================================================

    /** L'onglet a-t-il les en-têtes du format licenciés ? */
    private function estOngletLicencies(array $entetes): bool
    {
        $ligne = mb_strtolower(implode('|', array_map(strval(...), $entetes)));
        return str_contains($ligne, 'licence') && (str_contains($ligne, 'nom') || str_contains($ligne, 'noms'));
    }

    /** MUTATION / RENOUVELLEMENT / CREATION, ou la valeur brute si inconnue. */
    private function normaliserType(string $brut): ?string
    {
        $b = mb_strtolower(trim($brut));
        if ($b === '') { return null; }
        if (str_contains($b, 'muta'))   { return DossierLicence::TYPE_MUTATION; }
        if (str_contains($b, 'renouv')) { return DossierLicence::TYPE_RENOUVELLEMENT; }
        if (str_contains($b, 'crea') || str_contains($b, 'créa') || str_contains($b, 'nouv')) { return DossierLicence::TYPE_CREATION; }
        return mb_strtoupper(trim($brut));
    }

    /** 'OK' → PAYÉ ; tarif Gratuit → EXONÉRÉ ; un règlement partiel saisi → PARTIEL ; sinon EN_ATTENTE. */
    private function deduireStatutPaiement(string $colonnePaiement, string $tarif, bool $aDesAides): string
    {
        if (mb_strtolower(trim($colonnePaiement)) === 'ok') {
            return DossierLicence::PAIEMENT_PAYE;
        }
        if (str_contains(mb_strtolower($tarif), 'gratuit')) {
            return DossierLicence::PAIEMENT_EXONERE;
        }
        if ($aDesAides) {
            return DossierLicence::PAIEMENT_PARTIEL;
        }
        return DossierLicence::PAIEMENT_EN_ATTENTE;
    }

    private function lireDate(mixed $cellule): ?\DateTimeImmutable
    {
        if ($cellule === null || $cellule === '') { return null; }
        try {
            if (is_numeric($cellule)) {
                return \DateTimeImmutable::createFromMutable(XlsxDate::excelToDateTimeObject((float) $cellule));
            }
            return new \DateTimeImmutable((string) $cellule);
        } catch (\Exception) {
            return null;
        }
    }

    private function lireCodePostal(mixed $cellule): ?string
    {
        if ($cellule === null || $cellule === '') { return null; }
        // Excel renvoie souvent 80000.0 → on veut '80000'
        if (is_numeric($cellule)) {
            return (string) (int) $cellule;
        }
        return trim((string) $cellule) ?: null;
    }

    /**
     * Index « nom normalisé » → Joueur pour matcher les lignes Excel.
     * Deux clés par joueuse : « nom prenom » et « prenom nom ».
     *
     * @return array<string, Joueur>
     */
    private function indexJoueusesParNom(Club $club): array
    {
        $index = [];
        foreach ($this->joueurRepo->findBy(['club' => $club, 'isActive' => true]) as $j) {
            $cle1 = $this->normaliserNom(($j->getNom() ?? '') . ' ' . ($j->getPrenom() ?? ''));
            $cle2 = $this->normaliserNom(($j->getPrenom() ?? '') . ' ' . ($j->getNom() ?? ''));
            $index[$cle1] ??= $j;
            $index[$cle2] ??= $j;
        }
        return $index;
    }

    /** minuscules, sans accents, espaces compactés — tolère les Excel « sales ». */
    private function normaliserNom(string $nom): string
    {
        $n = mb_strtolower(trim($nom));
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n);
        if (is_string($translit) && $translit !== '') {
            $n = $translit;
        }
        return preg_replace('/\s+/', ' ', $n) ?? $n;
    }
}
