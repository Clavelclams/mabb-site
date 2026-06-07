<?php

declare(strict_types=1);

namespace App\Feed;

/**
 * DTO immuable représentant un item affiché dans le feed "Pour toi"
 * du dashboard manager.
 *
 * Pourquoi un DTO et pas un tableau ?
 *   - Typage strict (PHP 8 readonly) → erreurs détectées au build, pas au runtime.
 *   - Auto-documenté : un dev qui lit FeedItem sait exactement ce que contient un item.
 *   - Préparation Phase 2 : si on ajoute un champ (ex: score de pertinence),
 *     on le met ici et tous les usages sont obligés de s'adapter — pas de
 *     régression silencieuse comme avec un tableau associatif.
 *
 * Pourquoi readonly ?
 *   - Un item du feed est une PHOTO d'un événement à un instant T. Modifier ses
 *     champs après création n'a pas de sens — autant rendre ça impossible.
 *
 * Défense jury CDA : SRP — cette classe ne fait QUE décrire la structure d'un item.
 * L'agrégation (qui les crée) est dans FeedAggregator. Le rendu (comment on
 * les affiche) est dans le template. Trois responsabilités, trois endroits.
 */
final readonly class FeedItem
{
    /**
     * @param string             $type        Catégorie technique de l'item (ex: 'reunion_a_venir',
     *                                        'pv_non_lu', 'reunion_tenue'). Sert au template pour
     *                                        choisir le style + à la Phase 2 pour scorer la pertinence.
     * @param \DateTimeInterface $date        Date de référence pour le tri du feed (desc).
     *                                        Pour une réunion à venir : sa date. Pour un PV : date de publication.
     * @param string             $titre       Ligne principale affichée (ex: "AG du 12 juin").
     * @param string             $sousTitre   Ligne secondaire (ex: "Convocation — 14h, Salle du club").
     * @param string             $lien        URL absolue ou relative — sur quoi pointe le clic.
     * @param string             $icone       Classe Bootstrap Icons (ex: "bi-calendar-event-fill").
     * @param string             $couleur     Code couleur HEX/CSS pour border + accent visuel.
     * @param string             $labelType   Libellé humain affiché en badge (ex: "Réunion à venir").
     */
    public function __construct(
        public string $type,
        public \DateTimeInterface $date,
        public string $titre,
        public string $sousTitre,
        public string $lien,
        public string $icone,
        public string $couleur,
        public string $labelType,
    ) {}
}
