<?php

namespace App\Service;

use Smalot\PdfParser\Parser;

/**
 * TrombinoscopeParserService — parse un PDF de trombinoscope FFBB.
 *
 * Le service utilise smalot/pdfparser pour extraire le texte brut, le normalise
 * (tirets Unicode, espaces non-breakable), puis applique DEUX regex en parallèle
 * pour gérer les deux layouts pdfparser observés dans les exports FFBB :
 *
 *   FORMAT A — typique des PDFs catégorie jeunes (U13F AMIENS NORD) :
 *     Prénom - NOM
 *     LICENCE - DATE
 *     HDF... - CLUB
 *     DDN - Sexe[ - Nationalité]
 *     → Catégorie cherchée séparément via trouverCategoriePresDeLaFiche()
 *
 *   FORMAT C — typique des PDFs équipes seniors (RF3, NM1, etc.) :
 *     Licence XX - <Catégorie><Prénom> - NOM    (collés sans espace !)
 *     LICENCE - DATE
 *     DDN -  Sexe[ - Nationalité]                (double espace)
 *     HDF... - CLUB BASKETBALL<TAB>...
 *     → Catégorie capturée inline dans la regex
 *
 * Si le format change demain, on ajoute un FORMAT D ici sans toucher au reste
 * (Open/Closed Principle). Dédupe par licence FFBB (clé unique nationale).
 *
 * En cas d'échec total (0 joueuse détectée), le texte extrait est sauvegardé
 * dans var/temp_import/last_extract_*.txt pour diagnostic.
 *
 * Limitations V1 :
 *   - Pas d'extraction des photos (les images du PDF ne sont pas traitées)
 *   - Pas de gestion des cas où licence ou DDN sont vides
 *   - Pas de détection visages (V2 prévue en task #18)
 *
 * Le service NE crée RIEN en BDD : il retourne juste un array structuré que
 * le ImportTrombinoscopeController utilise pour la preview + création.
 */
class TrombinoscopeParserService
{
    /**
     * Parse un fichier PDF et retourne les métadonnées + joueuses détectées.
     *
     * @return array{
     *   equipe: array{nom: ?string, numero: ?string, club_code: ?string, club_nom: ?string, saison: ?string},
     *   joueuses: array<int, array{prenom: string, nom: string, licence: ?string, ddn: ?string, sexe: ?string, categorie: ?string, club_code: ?string, club_nom: ?string}>
     * }
     */
    public function parse(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            throw new \InvalidArgumentException("Fichier introuvable : $pdfPath");
        }

        $parser = new Parser();
        $pdf = $parser->parseFile($pdfPath);
        $texteBrut = $pdf->getText();

        // Normalisation cruciale : les PDFs FFBB utilisent souvent des tirets
        // typographiques (en-dash –, em-dash —, hyphen ‐...) qu'on ramène
        // tous au hyphen-minus ASCII pour que les regex puissent les matcher.
        // Idem pour les espaces non-breakable qui passent inaperçus à l'œil
        // mais cassent les `\s` selon les versions de PCRE.
        $texte = $this->normaliserTexte($texteBrut);

        $joueuses = $this->extraireJoueuses($texte);

        // Dump debug si rien n'a matché : on sauvegarde le texte extrait
        // à côté du PDF temporaire pour pouvoir diagnostiquer le format.
        // Sans ce dump, on travaille à l'aveugle.
        if (empty($joueuses)) {
            $debugPath = dirname($pdfPath) . '/last_extract_' . date('Ymd_His') . '.txt';
            @file_put_contents(
                $debugPath,
                "=== TEXTE BRUT (avant normalisation) ===\n"
                . $texteBrut
                . "\n\n=== TEXTE NORMALISÉ (passé aux regex) ===\n"
                . $texte
            );
        }

        return [
            'equipe'   => $this->extraireEquipe($texte),
            'joueuses' => $joueuses,
        ];
    }

    /**
     * Normalise le texte extrait pour rendre les regex robustes :
     *   - tous types de tirets Unicode → hyphen-minus ASCII (-)
     *   - espaces non-breakable → espace simple
     *
     * Sans cette étape, un PDF avec en-dash (–, U+2013) casse silencieusement
     * toutes les regex qui cherchent `-`. C'est LE piège classique avec smalot/pdfparser.
     */
    private function normaliserTexte(string $texte): string
    {
        // Tous les caractères tirets/dashes Unicode courants
        $tirets = [
            "\u{2010}",  // HYPHEN
            "\u{2011}",  // NON-BREAKING HYPHEN
            "\u{2012}",  // FIGURE DASH
            "\u{2013}",  // EN DASH (–)
            "\u{2014}",  // EM DASH (—)
            "\u{2015}",  // HORIZONTAL BAR
            "\u{2212}",  // MINUS SIGN
            "\u{FE58}",  // SMALL EM DASH
            "\u{FE63}",  // SMALL HYPHEN-MINUS
            "\u{FF0D}",  // FULLWIDTH HYPHEN-MINUS
        ];
        $texte = str_replace($tirets, '-', $texte);

        // Espaces non-breakable et autres whitespaces exotiques
        $espaces = [
            "\u{00A0}",  // NO-BREAK SPACE
            "\u{202F}",  // NARROW NO-BREAK SPACE
            "\u{2007}",  // FIGURE SPACE
            "\u{2009}",  // THIN SPACE
        ];
        $texte = str_replace($espaces, ' ', $texte);

        return $texte;
    }

    /**
     * Extrait les métadonnées d'équipe depuis l'en-tête de page.
     *
     * Pattern typique :
     *   Nom de l'équipe : U13F AMIENS NORD
     *   Numéro : 9
     *   Nom du Club : HDF0080036 - METROPOLE AMIENOISE
     *   Saison : 2023-2024
     */
    private function extraireEquipe(string $texte): array
    {
        $result = [
            'nom'        => null,
            'numero'     => null,
            'club_code'  => null,
            'club_nom'   => null,
            'saison'     => null,
        ];

        // Le texte FFBB met ces infos en tête, mais l'ordre peut varier
        // selon comment pdfparser lit la mise en page (les en-têtes sont en haut
        // de page mais peuvent apparaître à la fin du texte extrait).

        if (preg_match('/Nom de l\'équipe\s*:\s*(.+?)(?=\s*(?:Numéro|Nom du Club|Saison|$))/iu', $texte, $m)) {
            $result['nom'] = trim($m[1]);
        }
        if (preg_match('/Numéro\s*:\s*(\d+)/iu', $texte, $m)) {
            $result['numero'] = trim($m[1]);
        }
        if (preg_match('/Nom du Club\s*:\s*([A-Z]{3}\d+)\s*-\s*(.+?)(?=\s*(?:Saison|Nom de|$))/iu', $texte, $m)) {
            $result['club_code'] = trim($m[1]);
            $result['club_nom']  = trim($m[2]);
        }
        if (preg_match('/Saison\s*:?\s*(\d{4}-\d{4})/u', $texte, $m)) {
            $result['saison'] = trim($m[1]);
        }

        return $result;
    }

    /**
     * Extrait la liste des joueuses détectées dans le PDF.
     *
     * Stratégie : on tente DEUX formats de regex en parallèle, et on dédupe
     * par licence FFBB (clé unique). Permet de gérer les variations de mise
     * en page selon le type de trombinoscope généré par la FFBB.
     *
     * --- FORMAT A (trombinoscope catégorie jeunes type U13F) ---
     *   pdfparser lit dans cet ordre :
     *     Prénom - NOM
     *     LICENCE - DATE_DELIVRANCE
     *     HDF... - CLUB BASKETBALL
     *     DDN - Sexe[ - Nationalité]
     *   → la catégorie ("U13") est à part, cherchée via trouverCategoriePresDeLaFiche()
     *
     * --- FORMAT B (trombinoscope type RF3 / équipes seniors) ---
     *   pdfparser lit dans cet ordre :
     *     Prénom - NOM Licence XX - Catégorie    (← inline, sur la même ligne)
     *     LICENCE - DATE_DELIVRANCE
     *     DDN - Sexe[ - Nationalité]
     *     HDF... - CLUB BASKETBALL ...
     *   → la catégorie est capturée directement dans la regex
     *
     * Si demain la FFBB change encore le layout, on ajoute un FORMAT C ici
     * sans casser les deux premiers (Open/Closed Principle).
     *
     * @return array<int, array{prenom: string, nom: string, licence: ?string, ddn: ?string, sexe: ?string, categorie: ?string, club_code: ?string, club_nom: ?string}>
     */
    private function extraireJoueuses(string $texte): array
    {
        $joueuses = [];
        $licencesVues = [];  // dédupe entre les passes (licence FFBB = clé unique)

        // ====================================================================
        // FORMAT A : licence APRÈS le nom, club AVANT la DDN
        // ====================================================================
        $patternA = '/'
            // 1) Prénom + Nom : "Prénom - NOM" avec espaces obligatoires autour du tiret
            // pour éviter de matcher un prénom à tiret type "Rec-prefie"
            . '(?P<prenom>[A-ZÀ-Ÿa-zà-ÿ][A-ZÀ-Ÿa-zà-ÿ\'\- ]+?)'
            . '\s+-\s+'
            . '(?P<nom>[A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ\'\- ]+?)'

            // 2) Licence : "BC123456 - JJ/MM/YYYY"
            . '\s+(?P<licence>[A-Z]{2}\d+)'
            . '\s*-\s*(?P<date_licence>\d{2}\/\d{2}\/\d{4})'

            // 3) Code club + Nom club (capturés pour détection licences externes)
            . '\s+(?P<club_code>(?:HDF|[A-Z]{3})\d+)'
            . '\s*-\s*(?P<club_nom>[^\n]+?)'

            // 4) DDN - Sexe (- éventuellement Nationalité)
            . '\s+(?P<ddn>\d{2}\/\d{2}\/\d{4})'
            . '\s*-\s*(?P<sexe>Masculin|Féminin)'
            . '/u';

        if (preg_match_all($patternA, $texte, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $licence = trim($m['licence']);
                if (in_array($licence, $licencesVues, true)) {
                    continue;
                }
                $licencesVues[] = $licence;

                $sexe = trim($m['sexe']);
                $anneeNaissance = $this->anneeNaissanceDepuisLicence($licence);
                $joueuses[] = [
                    'prenom'             => $this->normaliserPrenom(trim($m['prenom'])),
                    'nom'                => $this->normaliserNom(trim($m['nom'])),
                    'licence'            => $licence,
                    'ddn'                => $this->convertirDateFR($m['ddn']),
                    'sexe'               => $sexe,
                    'categorie'          => $this->trouverCategoriePresDeLaFiche($texte, $m[0]),
                    'club_code'          => trim($m['club_code']),
                    'club_nom'           => trim($m['club_nom']),
                    'annee_naissance'    => $anneeNaissance,
                    'categorie_calculee' => $this->categorieSaisonCourante($anneeNaissance, $sexe),
                ];
            }
        }

        // ====================================================================
        // FORMAT C : trombinoscope type RF3 — smalot lit dans CET ordre exact :
        //   "Licence 0C - SeniorsNaomie - ASSANE"   (catégorie COLLÉE au prénom)
        //   "VT023803 - 19/09/2022"
        //   "28/10/2002 -  Féminin - Française"     (double espace avant Sexe)
        //   "HDF... - CLUB BASKETBALL\tA - Formule A"
        //
        // C'est très différent du FORMAT A : ici la catégorie PRÉCÈDE le nom
        // au lieu de le suivre, et le `Sportif` (mot-clé) est placé AVANT la
        // fiche au lieu d'après. C'est le layout visuel à 2 colonnes du PDF
        // que smalot/pdfparser sérialise dans cet ordre.
        // ====================================================================
        $patternC = '/'
            // 1) "Licence 0C - Seniors" (catégorie capturée directement)
            . 'Licence\s+[\dA-Z]+\s*-\s*'
            . '(?P<categorie>U\d{1,2}|Seniors|Loisir)'

            // 2) Prénom COLLÉ à la catégorie (Seniors+Naomie sans séparateur)
            // Le prénom commence forcément par une majuscule, ce qui crée
            // une frontière naturelle après "Seniors" / "U19" / etc.
            // Lazy + l'ancrage `\s+-\s+` ci-dessous fixe la fin du prénom.
            . '(?P<prenom>[A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ\'\- ]*?)'
            . '\s+-\s+'
            . '(?P<nom>[A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ\'\- ]+?)'

            // 3) Licence : "VT023803 - 19/09/2022"
            . '\s+(?P<licence>[A-Z]{2}\d+)'
            . '\s*-\s*(?P<date_licence>\d{2}\/\d{2}\/\d{4})'

            // 4) DDN - Sexe (\s* tolère le double espace avant "Féminin",
            //    nationalité optionnelle après)
            . '\s+(?P<ddn>\d{2}\/\d{2}\/\d{4})'
            . '\s*-\s*(?P<sexe>Masculin|Féminin)'
            // Nationalité optionnelle (Française, Belge, etc.)
            . '(?:\s*-\s*[A-Za-zÀ-ÿ]+)?'
            // 5) Code club + Nom club (s'arrête au tab, retour ligne)
            . '\s+(?P<club_code>(?:HDF|[A-Z]{3})\d+)'
            . '\s*-\s*(?P<club_nom>[^\t\n]+?)(?=[\t\n])'
            . '/u';

        if (preg_match_all($patternC, $texte, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $licence = trim($m['licence']);
                if (in_array($licence, $licencesVues, true)) {
                    continue;
                }
                $licencesVues[] = $licence;

                $sexe = trim($m['sexe']);
                $anneeNaissance = $this->anneeNaissanceDepuisLicence($licence);
                $joueuses[] = [
                    'prenom'             => $this->normaliserPrenom(trim($m['prenom'])),
                    'nom'                => $this->normaliserNom(trim($m['nom'])),
                    'licence'            => $licence,
                    'ddn'                => $this->convertirDateFR($m['ddn']),
                    'sexe'               => $sexe,
                    'categorie'          => trim($m['categorie']),
                    'club_code'          => trim($m['club_code']),
                    'club_nom'           => trim($m['club_nom']),
                    'annee_naissance'    => $anneeNaissance,
                    'categorie_calculee' => $this->categorieSaisonCourante($anneeNaissance, $sexe),
                ];
            }
        }

        return $joueuses;
    }

    /**
     * Cherche la catégorie (U12, U13, Seniors...) qui suit la fiche.
     * Le PDF a la chaîne "Licence 0C - U12" SOIT avant la fiche, SOIT après.
     */
    private function trouverCategoriePresDeLaFiche(string $texteComplet, string $blocFiche): ?string
    {
        $pos = strpos($texteComplet, $blocFiche);
        if ($pos === false) {
            return null;
        }

        // Cherche dans les 300 caractères suivants
        $fenetre = substr($texteComplet, $pos, strlen($blocFiche) + 300);

        if (preg_match('/Licence\s+[\dA-Z]+\s*-\s*(U\d{1,2}|Seniors|Loisir)/iu', $fenetre, $m)) {
            return $m[1];
        }
        // Si pas trouvé en aval, cherche en amont (200 caractères)
        $start = max(0, $pos - 200);
        $avant = substr($texteComplet, $start, $pos - $start);
        if (preg_match('/Licence\s+[\dA-Z]+\s*-\s*(U\d{1,2}|Seniors|Loisir)/iu', $avant, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extrait l'année de naissance des deux premiers chiffres du numéro de
     * licence FFBB. Convention métier : licences format "XX_yynnnnnn" où
     * yy = 2 derniers chiffres de l'année de naissance.
     *
     * Heuristique 2 chiffres → 4 chiffres : si yy > année courante (2 chiffres),
     * on est sur un siècle passé (ex: 99 en 2026 → 1999, pas 2099).
     *
     * Exemples : VT991127 → 1999, BC070637 → 2007, BC060001 → 2006.
     */
    private function anneeNaissanceDepuisLicence(?string $licence): ?int
    {
        if (!$licence || !preg_match('/^[A-Z]{2}(\d{2})/', $licence, $m)) {
            return null;
        }
        $yy = (int) $m[1];
        $courantYy = (int) date('y');
        return $yy > $courantYy ? 1900 + $yy : 2000 + $yy;
    }

    /**
     * Calcule la catégorie FFBB d'un joueur pour la saison sportive en cours,
     * basée sur son année de naissance et son sexe.
     *
     * Saison sportive française = septembre N → juin N+1. La catégorie d'âge
     * est déterminée par l'âge au 31/12 de l'année de fin de saison.
     *
     * Choix métier MABB : pas de U19/U20/U21 dans CATEGORIES, car ces équipes
     * sont rares dans la pratique (peu de joueurs dans cette tranche, ils
     * intègrent vite l'effectif Senior). Convention : dès 19 ans on bascule
     * sur Senior, et U18 reste l'unique catégorie à 1 an au-dessus de U17.
     *
     * Mapping aligné sur Equipe::CATEGORIES :
     *   ≥ 19 ans → Senior F/H (selon sexe) — couvre U19/U20/U21 historiques
     *   = 18    → U18
     *   16-17   → U17
     *   14-15   → U15
     *   12-13   → U13
     *   10-11   → U11
     *   8-9     → U9
     *   ≤ 7     → U7
     *
     * @param string|null $sexe "Masculin", "Féminin" ou null
     */
    private function categorieSaisonCourante(?int $anneeNaissance, ?string $sexe): ?string
    {
        if ($anneeNaissance === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $mois = (int) $now->format('n');
        $anneeCivile = (int) $now->format('Y');
        // De juillet à décembre → on est dans la saison qui finit l'année suivante
        // De janvier à juin     → on est dans la saison qui finit cette année
        $anneeFinSaison = $mois >= 7 ? $anneeCivile + 1 : $anneeCivile;

        $age = $anneeFinSaison - $anneeNaissance;

        if ($age >= 19) {
            return $sexe === 'Masculin' ? 'Senior H' : 'Senior F';
        }
        if ($age === 18) return 'U18';
        if ($age >= 16)  return 'U17';
        if ($age >= 14)  return 'U15';
        if ($age >= 12)  return 'U13';
        if ($age >= 10)  return 'U11';
        if ($age >= 8)   return 'U9';
        return 'U7';
    }

    /**
     * Convertit "DD/MM/YYYY" → "YYYY-MM-DD" pour Doctrine.
     */
    private function convertirDateFR(string $dateFr): ?string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateFr, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    /**
     * Normalise un prénom : première lettre majuscule, le reste minuscule
     * (avec gestion des prénoms composés et apostrophes).
     */
    private function normaliserPrenom(string $prenom): string
    {
        return mb_convert_case(trim($prenom), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Normalise un nom : tout en majuscules (convention FFBB et clubs).
     */
    private function normaliserNom(string $nom): string
    {
        return mb_strtoupper(trim($nom), 'UTF-8');
    }
}
