<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Sport\OperationTresorerie;
use App\Repository\Sport\OperationTresorerieRepository;

/**
 * Exporte les opérations de trésorerie en CSV — Bureau Phase D.5.
 *
 * BUT : permettre au trésorier de passer le bâton à un expert-comptable
 * (ou de bosser dans Excel) sans qu'il doive saisir à la main.
 *
 * FORMAT CHOISI :
 *   - Encodage UTF-8 + BOM (Byte Order Mark "\xEF\xBB\xBF" en tête)
 *     → sans le BOM, Excel français interprète l'UTF-8 comme du Latin-1
 *       et casse les accents. Avec le BOM, Excel détecte UTF-8 correctement.
 *   - Séparateur ; (point-virgule) → convention Excel FR (la virgule est
 *     le séparateur décimal en français).
 *   - Guillemets pour les champs contenant ;, ", saut de ligne ou virgule.
 *   - Fin de ligne CRLF (\r\n) → compatibilité Excel Windows.
 *
 * MÉMOIRE :
 *   - Pour MVP, on construit le CSV en string puis on renvoie.
 *   - Si un jour on a 50 000 opérations, il faudra streamer ligne par ligne
 *     via StreamedResponse pour éviter de tout charger en RAM. Pas pour D.5.
 *
 * Défense jury CDA :
 *   - Pourquoi un service ? Parce que cette logique de formatage n'a rien
 *     à faire dans le controller (SRP). Et testable indépendamment.
 */
final class TresorerieExporter
{
    /** Byte Order Mark UTF-8 — Excel FR ne lit pas correctement sans. */
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    /** Séparateur de champs — convention Excel français. */
    private const SEPARATEUR = ';';

    public function __construct(
        private readonly OperationTresorerieRepository $operationRepository,
    ) {}

    /**
     * Génère le contenu CSV des opérations d'un club sur une période, avec
     * filtres optionnels par type et catégorie.
     *
     * @param Club               $club
     * @param \DateTimeInterface $debut    Date début (inclusive)
     * @param \DateTimeInterface $fin      Date fin (inclusive)
     * @param string|null        $type     OperationTresorerie::TYPE_* ou null
     * @param string|null        $categorie OperationTresorerie::CAT_* ou null
     */
    public function exporterOperations(
        Club $club,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
        ?string $type = null,
        ?string $categorie = null,
    ): string {
        // Récupération des opérations sur la période
        $operations = $this->operationRepository->findByClubAndPeriode($club, $debut, $fin);

        // Filtre supplémentaire en PHP plutôt que d'ajouter à la query — l'usage
        // typique est "tout exporter sur la période", filtre type/cat reste rare.
        if ($type !== null) {
            $operations = array_filter($operations, fn(OperationTresorerie $o) => $o->getType() === $type);
        }
        if ($categorie !== null) {
            $operations = array_filter($operations, fn(OperationTresorerie $o) => $o->getCategorie() === $categorie);
        }

        // En-têtes humaines + lignes
        $lignes = [
            $this->ligne([
                'Date',
                'Type',
                'Catégorie',
                'Libellé',
                'Montant (€)',
                'Montant signé (€)',
                'Justificatif',
                'Notes',
                'Saisi par',
                'Créée le',
            ]),
        ];

        foreach ($operations as $op) {
            $lignes[] = $this->ligne([
                $op->getDate()->format('d/m/Y'),
                $op->getType() === OperationTresorerie::TYPE_RECETTE ? 'Recette' : 'Dépense',
                $op->getCategorieLabel(),
                $op->getLibelle(),
                // Montant brut (toujours positif) pour calcul Excel ultérieur
                str_replace('.', ',', $op->getMontant()),
                // Montant signé pour somme directe (recette positive, dépense négative)
                str_replace('.', ',', $op->getMontantSigne()),
                $op->hasJustificatif() ? 'Oui' : 'Non',
                $op->getNotes() ?? '',
                $op->getCreatedBy()?->getEmail() ?? '',
                $op->getCreatedAt()->format('d/m/Y H:i'),
            ]);
        }

        return self::BOM_UTF8 . implode("\r\n", $lignes) . "\r\n";
    }

    /**
     * Construit le nom de fichier suggéré au téléchargement.
     *
     * Format : tresorerie-{nom-club-slug}-{debut}-au-{fin}.csv
     */
    public function nomFichier(Club $club, \DateTimeInterface $debut, \DateTimeInterface $fin): string
    {
        $clubSlug = $this->slugify($club->getNom() ?? 'club');
        return sprintf(
            'tresorerie-%s-%s-au-%s.csv',
            $clubSlug,
            $debut->format('Y-m-d'),
            $fin->format('Y-m-d'),
        );
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Formate une ligne CSV : escape chaque champ et joint avec le séparateur.
     *
     * Règles d'escape (RFC 4180 — standard CSV) :
     *   - Si le champ contient le séparateur, des guillemets ou des sauts
     *     de ligne, on l'entoure de guillemets doubles.
     *   - Dans un champ encadré, les guillemets internes sont doublés ("" pour ").
     *
     * @param array<int, string> $champs
     */
    private function ligne(array $champs): string
    {
        $echappes = array_map([$this, 'escapeChamp'], $champs);
        return implode(self::SEPARATEUR, $echappes);
    }

    private function escapeChamp(string $champ): string
    {
        // Si rien de spécial, on renvoie tel quel (économise des octets)
        if (!preg_match('/[;"\r\n,]/', $champ)) {
            return $champ;
        }
        // Sinon on échappe et on encadre
        $champEchape = str_replace('"', '""', $champ);
        return '"' . $champEchape . '"';
    }

    /**
     * Slug simple — minuscules + remplace tout non-alphanum par tiret.
     * Pas besoin d'une vraie translit (intl) pour un nom de fichier.
     */
    private function slugify(string $texte): string
    {
        $texte = mb_strtolower($texte, 'UTF-8');
        // Translit basique des accents courants
        $texte = strtr($texte, [
            'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
            'ç'=>'c',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','í'=>'i',
            'ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
            'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
            'ÿ'=>'y','ñ'=>'n',
        ]);
        $texte = preg_replace('/[^a-z0-9]+/', '-', $texte) ?? '';
        return trim($texte, '-') ?: 'club';
    }
}
