<?php

declare(strict_types=1);

namespace App\Service\Secretariat;

/**
 * Normalisation de noms pour les rapprochements SANS DOUBLON [V2.4h].
 *
 * Règle d'or (Clavel, 09/07/2026) : « on a déjà une bonne base de joueuses
 * de l'an dernier — quand elles refont leur licence, surtout pas de
 * doublons ». Tout rapprochement nom+prénom (import Excel, conversion de
 * pré-inscription, préparation de saison) passe par CETTE normalisation :
 * minuscules, sans accents, espaces compactés.
 */
final class NomOutil
{
    private function __construct() {}

    public static function normaliser(string $nom): string
    {
        $n = mb_strtolower(trim($nom));
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n);
        if (is_string($translit) && $translit !== '') {
            $n = $translit;
        }
        return preg_replace('/\s+/', ' ', $n) ?? $n;
    }
}
