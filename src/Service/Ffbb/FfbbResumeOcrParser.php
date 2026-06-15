<?php

declare(strict_types=1);

namespace App\Service\Ffbb;

use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Presence;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\EvaluationMatchRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\PresenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * [B-FFBB-OCR 15/06/2026] Parse le texte OCR Vision d'un PDF resume FFBB
 * et persiste les EvaluationMatch pour les joueuses MABB.
 *
 * 🎯 PORTÉE : ce parser ne capture QUE les stats que le PDF resume FFBB fournit :
 *   - Minutes jouées (Tps de jeu)
 *   - Starter (5 de départ : marqué X ou ☑)
 *   - Points marqués
 *   - Tirs réussis (total)
 *   - 3pts réussis
 *   - 2pts intérieurs réussis
 *   - 2pts extérieurs réussis (→ on additionne 2pt INT + 2pt EXT pour Tirs2ptsReussis)
 *   - LF réussis
 *   - Fautes commises
 *
 * 🚫 NON CAPTURÉ par la FFBB (viendra de Stats Live MABB) :
 *   - Tirs TENTÉS (% shooting impossible depuis ce PDF)
 *   - Rebonds offensifs/défensifs
 *   - Passes décisives
 *   - Interceptions
 *   - Contres
 *   - Pertes de balle
 *
 * 📐 FORMAT TEXTE VISION OBSERVÉ (test 15/06 sur resume_match5.pdf) :
 *   Chaque cellule du tableau sort sur SA propre ligne.
 *   Structure pour une joueuse :
 *     N°         (4, 5, 10, ...)
 *     NOM Prénom (Poix, Laëtitia ou LEFEVRE, Jody)
 *     [X ou ☑]   ← starter, ligne optionnelle (pas présente si pas starter)
 *     mm:ss      (20:21)
 *     Pts        (8)
 *     Tirs R     (4)
 *     3pts R     (0)
 *     2pts INT R (3)
 *     2pts EXT R (1)
 *     LF R       (0)
 *     Ftes       (1)
 *
 * 🎯 DÉTECTION LOCAUX / VISITEURS :
 *   On regarde où apparaît MABB ("METROPOLE", "AMIENOISE", "AMIE NOISE")
 *   dans les sections LOCAUX ou VISITEURS du PDF. Plus fiable que de se baser
 *   sur `rencontre.domicile` (qui peut être incohérent avec le PDF).
 *
 * Idempotent : ré-importer le même PDF met à jour les EvaluationMatch
 * existantes au lieu de créer des doublons.
 */
class FfbbResumeOcrParser
{
    /** Patterns indicateurs MABB dans le texte OCR (insensible casse) */
    private const MABB_PATTERNS = ['METROPOLE', 'AMIENOISE', 'AMIE NOISE'];

    /** Caractères qui marquent un starter (5 de départ) */
    private const STARTER_MARKERS = ['X', 'x', '☑', 'Х', '✓']; // X latin, X cyrillique vu sur l'OCR

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EvaluationMatchRepository $evalRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly PresenceRepository $presenceRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse le texte OCR + persiste les EvaluationMatch pour MABB.
     *
     * @return array{joueuses_parsees: int, joueuses_matchees: int, evals_creees: int, evals_majees: int, warnings: string[]}
     */
    public function parseEtPersister(Rencontre $rencontre, string $ocrText): array
    {
        $result = [
            'joueuses_parsees' => 0,
            'joueuses_matchees' => 0,
            'evals_creees' => 0,
            'evals_majees' => 0,
            'presences_creees' => 0,
            'presences_majees' => 0,
            'absences_marquees' => 0,
            'warnings' => [],
        ];

        // === 1. Identifier où MABB est : LOCAUX ou VISITEURS ? ===
        $mabbSide = $this->detecterCoteMabb($ocrText);
        if ($mabbSide === null) {
            $result['warnings'][] = 'Impossible de détecter le côté MABB (LOCAUX/VISITEURS) dans le PDF. Aucun parsing.';
            $this->logger->warning('OCR Parser : côté MABB non détecté', [
                'rencontre_id' => $rencontre->getId(),
            ]);
            return $result;
        }
        $this->logger->info('OCR Parser : MABB détecté côté ' . $mabbSide);

        // === 2. Extraire le bloc de texte du tableau MABB uniquement ===
        $blocMabb = $this->extraireBlocTableau($ocrText, $mabbSide);
        if ($blocMabb === '') {
            $result['warnings'][] = "Bloc tableau {$mabbSide} introuvable dans le texte OCR.";
            return $result;
        }

        // === 3. Parser les lignes joueuses du bloc MABB ===
        $lignesJoueuses = $this->extraireLignesJoueuses($blocMabb);
        $result['joueuses_parsees'] = count($lignesJoueuses);

        if (empty($lignesJoueuses)) {
            $result['warnings'][] = "Aucune ligne joueuse détectée dans le bloc {$mabbSide}.";
            return $result;
        }

        // === 4. Cache joueuses de l'équipe + évals existantes ===
        $joueuses = $this->joueurRepo->findBy([
            'equipe' => $rencontre->getEquipe(),
            'isActive' => true,
        ]);
        $evalsExistantes = $this->evalRepo->evaluationsRencontre($rencontre);
        $evalsParJoueur = [];
        foreach ($evalsExistantes as $e) {
            $evalsParJoueur[$e->getJoueur()->getId()] = $e;
        }

        // === 4.5 Pré-charge les Presence existantes (pour idempotence) ===
        $presencesExistantes = $this->presenceRepo->findBy(['rencontre' => $rencontre]);
        $presencesParJoueur = [];
        foreach ($presencesExistantes as $p) {
            $presencesParJoueur[$p->getJoueur()->getId()] = $p;
        }

        // === 5. Pour chaque ligne, matcher la joueuse + créer/maj EvaluationMatch + Presence ===
        $joueursPresentsIds = [];  // pour distinguer ensuite les absents

        foreach ($lignesJoueuses as $ligne) {
            $joueur = $this->matchJoueurParNom($ligne['nom'], $joueuses);
            if ($joueur === null) {
                $result['warnings'][] = sprintf(
                    'Joueuse non trouvée dans le club : "%s" (n°%s). Ligne ignorée.',
                    $ligne['nom'],
                    $ligne['numero'] ?? '?',
                );
                continue;
            }
            $result['joueuses_matchees']++;
            $joueursPresentsIds[] = $joueur->getId();

            // === Presence : marquer comme présente (source SCAN) ===
            // Idempotence : on respecte les Presence saisies manuellement par le coach
            $presence = $presencesParJoueur[$joueur->getId()] ?? null;
            if ($presence !== null && $presence->getSource() === Presence::SOURCE_MANUEL) {
                // Coach a déjà pointé manuellement → on ne touche pas
            } elseif ($presence !== null) {
                // Presence SCAN existante → on UPDATE
                $presence->setPresent(true);
                $presence->setMotifAbsence(null);
                $result['presences_majees']++;
            } else {
                // Pas de Presence → CREATE
                $presence = new Presence();
                $presence->setJoueur($joueur);
                $presence->setRencontre($rencontre);
                $presence->setPresent(true);
                $presence->setSource(Presence::SOURCE_SCAN);
                $this->em->persist($presence);
                $result['presences_creees']++;
            }

            $eval = $evalsParJoueur[$joueur->getId()] ?? null;
            $isNew = ($eval === null);
            if ($isNew) {
                $eval = new EvaluationMatch();
                $eval->setJoueur($joueur);
                $eval->setRencontre($rencontre);
            }

            // Application des stats parsées (uniquement celles que la FFBB donne)
            $eval->setIsStarter($ligne['starter'] ?? false);
            $eval->setMinutesJouees($ligne['minutes'] ?? 0);
            // Tirs 2pts réussis = INT + EXT (l'entité ne distingue pas)
            $tirs2R = ($ligne['t2int_r'] ?? 0) + ($ligne['t2ext_r'] ?? 0);
            $eval->setTirs2ptsReussis($tirs2R);
            $eval->setTirs3ptsReussis($ligne['t3_r'] ?? 0);
            $eval->setLancersReussis($ligne['lf_r'] ?? 0);
            $eval->setFautesCommises($ligne['ftes'] ?? 0);
            // [15/06/2026] ON N'ÉCRIT PAS les tirs TENTÉS depuis l'OCR FFBB :
            // la FFBB ne fournit QUE les réussis (le PDF resume ne donne pas les manqués).
            // Mettre tentes=réussis donnerait 100% partout, ce qui serait factuellement faux.
            // Les tentes resteront à 0 → template PIRB affichera juste "X réussis" sans %.
            // Les tentes seront remplis UNIQUEMENT par Stats Live (qui capture les tirs manqués)
            // ou par saisie manuelle du coach via /rencontres/{id}/evals.

            // Note coach pour traçabilité de la source
            $eval->setNotesCoach(
                ($eval->getNotesCoach() ?? '') . "\n[OCR FFBB import " . (new \DateTimeImmutable())->format('d/m/Y H:i') . "]"
            );

            if ($isNew) {
                $this->em->persist($eval);
                $result['evals_creees']++;
            } else {
                $result['evals_majees']++;
            }
        }

        // === 6. Marquer les joueuses ABSENTES du PDF comme absentes
        // (= toutes les joueuses de l'équipe MABB qui n'ont pas été détectées dans le tableau)
        // Idempotence : on respecte les Presence MANUELLES, on n'écrase que les SCAN ou rien
        foreach ($joueuses as $joueur) {
            if (in_array($joueur->getId(), $joueursPresentsIds, true)) {
                continue; // déjà marquée présente
            }
            $presence = $presencesParJoueur[$joueur->getId()] ?? null;
            if ($presence !== null && $presence->getSource() === Presence::SOURCE_MANUEL) {
                continue; // coach a pointé manuellement → respect
            }
            if ($presence !== null) {
                // Presence SCAN existante → on UPDATE en absente
                $presence->setPresent(false);
                $presence->setMotifAbsence($presence->getMotifAbsence() ?? 'Non détectée sur la feuille FFBB');
                $result['absences_marquees']++;
            } else {
                // Pas de Presence → CREATE absente
                $presence = new Presence();
                $presence->setJoueur($joueur);
                $presence->setRencontre($rencontre);
                $presence->setPresent(false);
                $presence->setMotifAbsence('Non détectée sur la feuille FFBB');
                $presence->setSource(Presence::SOURCE_SCAN);
                $this->em->persist($presence);
                $result['absences_marquees']++;
            }
        }

        $this->em->flush();

        $this->logger->info('OCR Parser : terminé', [
            'rencontre_id' => $rencontre->getId(),
            'parsées' => $result['joueuses_parsees'],
            'matchées' => $result['joueuses_matchees'],
            'evals_créées' => $result['evals_creees'],
            'evals_majées' => $result['evals_majees'],
            'présences_créées' => $result['presences_creees'],
            'présences_majées' => $result['presences_majees'],
            'absences_marquées' => $result['absences_marquees'],
            'warnings' => count($result['warnings']),
        ]);

        return $result;
    }

    /**
     * Cherche dans le texte FFBB si MABB est Équipe A (LOCAUX) ou Équipe B (VISITEURS).
     *
     * 🎯 STRATÉGIE FIABLE : on cherche les libellés "Équipe A" et "Équipe B"
     * dans l'en-tête du PDF, et on regarde lequel contient le nom MABB.
     *
     * Format attendu (testé sur resume_match5.pdf via Vision) :
     *   "Équipe A BRAY BASKET BALL"
     *   "Équipe B METROPOLE AMIE NOISE BASKETBALL"
     *
     * → Équipe A = LOCAUX (équipe qui reçoit, à domicile)
     * → Équipe B = VISITEURS (équipe qui se déplace)
     *
     * On extrait les 2 noms d'équipe via regex puis on cherche MABB dans chacun.
     * Plus robuste que de comparer des positions de mots-clés dans le texte
     * (la détection précédente faisait des faux positifs car "METROPOLE"
     * apparaît dans l'en-tête, donc entre les mots "LOCAUX" et "VISITEURS"
     * qui sont en bas du PDF).
     */
    private function detecterCoteMabb(string $text): ?string
    {
        $equipeA = null;
        $equipeB = null;

        if (preg_match('/Équipe\s*A\s+([^\r\n]+)/u', $text, $m)) {
            $equipeA = trim($m[1]);
        }
        if (preg_match('/Équipe\s*B\s+([^\r\n]+)/u', $text, $m)) {
            $equipeB = trim($m[1]);
        }

        $this->logger->info('OCR détection équipes', [
            'equipe_a' => $equipeA,
            'equipe_b' => $equipeB,
        ]);

        foreach (self::MABB_PATTERNS as $pattern) {
            if ($equipeA !== null && mb_stripos($equipeA, $pattern) !== false) {
                return 'LOCAUX';
            }
            if ($equipeB !== null && mb_stripos($equipeB, $pattern) !== false) {
                return 'VISITEURS';
            }
        }

        return null;
    }

    /**
     * Extrait le bloc texte du tableau LOCAUX ou VISITEURS uniquement.
     * Le bloc commence à "LOCAUX"/"VISITEURS" et finit à "Total Équipe" ou
     * au début du tableau suivant.
     */
    private function extraireBlocTableau(string $text, string $cote): string
    {
        // Cherche la position du marqueur de début et du "Total Équipe" suivant
        $marqueurDebut = $cote;
        $start = mb_strpos($text, $marqueurDebut);
        if ($start === false) return '';

        $remaining = mb_substr($text, $start);
        // "Total Équipe" est notre marqueur de fin
        $endPos = mb_strpos($remaining, 'Total Équipe');
        if ($endPos === false) {
            // Pas de marqueur fin → on prend jusqu'au prochain VISITEURS ou la fin
            if ($cote === 'LOCAUX') {
                $altEnd = mb_strpos($remaining, 'VISITEURS');
                if ($altEnd !== false) {
                    return mb_substr($remaining, 0, $altEnd);
                }
            }
            return $remaining;
        }

        return mb_substr($remaining, 0, $endPos);
    }

    /**
     * Parse le bloc texte d'un tableau et extrait les lignes joueuses.
     *
     * Stratégie : on tokenise le texte ligne par ligne, on cherche les numéros
     * maillot (1-2 chiffres seuls sur une ligne), et on aspire ce qui suit
     * jusqu'au prochain numéro ou marqueur de fin.
     *
     * @return array<int, array{numero: int, nom: string, starter: bool, minutes: int, points: int, tirs_r: int, t3_r: int, t2int_r: int, t2ext_r: int, lf_r: int, ftes: int}>
     */
    private function extraireLignesJoueuses(string $bloc): array
    {
        // Découper en lignes individuelles, retirer les vides
        $lignes = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $bloc) ?: []),
            fn($l) => $l !== ''
        ));

        $joueuses = [];
        $n = count($lignes);
        $i = 0;

        // On démarre la recherche après l'en-tête (skip les "N°", "Maillot", etc.)
        // L'en-tête a typiquement les libellés "Maillot", "NOM Prénom", "5 de départ", "Tps de jeu"...
        // On cherche le PREMIER numéro maillot 1-2 chiffres (et pas une stat en cours d'en-tête)
        while ($i < $n) {
            $ligne = $lignes[$i];

            // Numéro maillot = 1-2 chiffres ET pas dans la zone des libellés
            // Critère : la ligne SUIVANTE doit être un nom (lettres avec virgule possible)
            if (preg_match('/^(\d{1,2})$/', $ligne, $m)) {
                $numero = (int) $m[1];

                // La ligne suivante doit être un nom (au moins 3 caractères, contient une lettre)
                if ($i + 1 < $n && preg_match('/[a-zA-ZÀ-ÿ]{2,}/u', $lignes[$i + 1])
                    // Heuristique : un nom ne commence pas par un chiffre seul
                    && !preg_match('/^\d+$/', $lignes[$i + 1])) {
                    $nom = $lignes[$i + 1];

                    // Collecter les valeurs suivantes jusqu'au prochain numéro maillot
                    // (ou jusqu'à Total Équipe / fin du tableau)
                    $valeurs = [];
                    $starter = false;
                    $j = $i + 2;
                    while ($j < $n) {
                        $val = $lignes[$j];
                        // Stop si on retombe sur un numéro maillot (qui PRÉCÈDE un nom)
                        if (preg_match('/^\d{1,2}$/', $val)
                            && ($j + 1 < $n)
                            && preg_match('/[a-zA-ZÀ-ÿ]{2,}/u', $lignes[$j + 1])
                            && !preg_match('/^\d+$/', $lignes[$j + 1])
                            && (int) $val < 100  // les totaux peuvent avoir des nombres > 100
                            && count($valeurs) >= 3) {
                            break;
                        }
                        // Stop si Total Équipe, Banc, etc.
                        if (preg_match('/^Total/u', $val) || mb_strtoupper($val) === 'TOTAL') {
                            break;
                        }
                        // Marqueur starter
                        if (in_array($val, self::STARTER_MARKERS, true)) {
                            $starter = true;
                            $j++;
                            continue;
                        }
                        $valeurs[] = $val;
                        $j++;
                    }

                    // Maintenant on map les valeurs aux colonnes :
                    // [0] = Tps mm:ss
                    // [1] = Pts
                    // [2] = Tirs R (total réussis)
                    // [3] = 3pts R
                    // [4] = 2pts INT R
                    // [5] = 2pts EXT R
                    // [6] = LF R
                    // [7] = Ftes
                    $joueuse = [
                        'numero' => $numero,
                        'nom' => $nom,
                        'starter' => $starter,
                        'minutes' => isset($valeurs[0]) ? $this->parseMinutes($valeurs[0]) : 0,
                        'points' => isset($valeurs[1]) ? (int) $valeurs[1] : 0,
                        'tirs_r' => isset($valeurs[2]) ? (int) $valeurs[2] : 0,
                        't3_r' => isset($valeurs[3]) ? (int) $valeurs[3] : 0,
                        't2int_r' => isset($valeurs[4]) ? (int) $valeurs[4] : 0,
                        't2ext_r' => isset($valeurs[5]) ? (int) $valeurs[5] : 0,
                        'lf_r' => isset($valeurs[6]) ? (int) $valeurs[6] : 0,
                        'ftes' => isset($valeurs[7]) ? (int) $valeurs[7] : 0,
                    ];

                    // Filtre : skip si vraiment ligne vide (probablement remplissage tableau)
                    if ($joueuse['minutes'] > 0 || $joueuse['points'] > 0 || $joueuse['tirs_r'] > 0) {
                        // [15/06/2026] Garde-fou anti-aberration OCR : si Vision a sorti
                        // un nombre variable de cellules (cellules vides omises), le mapping
                        // positionnel peut faire glisser des valeurs et donner des chiffres
                        // absurdes (ex: tirs2pts_reussis=142 sur 14 min). On rejette toute
                        // ligne avec des valeurs manifestement aberrantes.
                        // Seuils basés sur les records FFBB féminines amateurs.
                        $absurde = (
                            $joueuse['minutes'] > 50      // un match dure 40min max
                            || $joueuse['points'] > 80    // top scoreur U15F = ~50 pts
                            || $joueuse['tirs_r'] > 30    // max raisonnable
                            || $joueuse['t3_r'] > 15
                            || $joueuse['t2int_r'] > 25
                            || $joueuse['t2ext_r'] > 15
                            || $joueuse['lf_r'] > 25
                            || $joueuse['ftes'] > 10      // FFBB = 5 fautes max avant éliminatoire
                        );
                        if ($absurde) {
                            // On garde la joueuse mais on remet ses stats à 0 pour ne pas
                            // polluer la base. Le coach pourra saisir manuellement via UI.
                            $joueuse['minutes'] = 0;
                            $joueuse['points'] = 0;
                            $joueuse['tirs_r'] = 0;
                            $joueuse['t3_r'] = 0;
                            $joueuse['t2int_r'] = 0;
                            $joueuse['t2ext_r'] = 0;
                            $joueuse['lf_r'] = 0;
                            $joueuse['ftes'] = 0;
                            $joueuse['_aberrant'] = true;
                        }
                        $joueuses[] = $joueuse;
                    }

                    $i = $j; // saut à la fin de cette joueuse
                    continue;
                }
            }
            $i++;
        }

        return $joueuses;
    }

    /** "20:21" → 20 minutes (on ignore les secondes pour le stockage int). */
    private function parseMinutes(string $s): int
    {
        if (preg_match('/^(\d+):(\d+)$/', $s, $m)) {
            return (int) $m[1];
        }
        return (int) $s;
    }

    /**
     * Cherche dans la liste des joueurs MABB celui qui correspond au nom OCR.
     * Format attendu : "NOM, Prénom" ou "Prénom, NOM" (Vision peut varier).
     * Utilise Levenshtein avec tolérance 3 caractères.
     *
     * @param Joueur[] $joueurs Joueurs du club à matcher
     */
    private function matchJoueurParNom(string $nomOcr, array $joueurs): ?Joueur
    {
        $nomNorm = $this->normaliser($nomOcr);
        $bestMatch = null;
        $bestScore = PHP_INT_MAX;

        foreach ($joueurs as $j) {
            $candidat1 = $this->normaliser($j->getNom() . ' ' . $j->getPrenom());
            $candidat2 = $this->normaliser($j->getPrenom() . ' ' . $j->getNom());
            $candidat3 = $this->normaliser($j->getNom() . ', ' . $j->getPrenom());
            $candidat4 = $this->normaliser($j->getPrenom() . ', ' . $j->getNom());

            $distance = min(
                levenshtein($nomNorm, $candidat1),
                levenshtein($nomNorm, $candidat2),
                levenshtein($nomNorm, $candidat3),
                levenshtein($nomNorm, $candidat4),
            );

            if ($distance < $bestScore && $distance <= 4) {
                $bestScore = $distance;
                $bestMatch = $j;
            }
        }

        return $bestMatch;
    }

    /** Normalise une chaîne : lowercase + accents → ASCII + espaces compactés. */
    private function normaliser(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        return preg_replace('/\s+/', ' ', $s);
    }
}
