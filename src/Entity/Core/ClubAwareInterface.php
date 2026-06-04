<?php

namespace App\Entity\Core;

/**
 * ClubAwareInterface : contrat pour toute entité qui appartient à un club.
 *
 * Le ClubVoter sait extraire le Club d'une entité qui implémente cette interface,
 * sans avoir besoin de connaître le type concret. C'est l'Open/Closed Principle :
 *   - le Voter est FERMÉ à modification (on n'y touche plus quand on ajoute une nouvelle entité)
 *   - le Voter est OUVERT à extension (toute entité nouvelle qui implémente
 *     l'interface est automatiquement protégée par le Voter)
 *
 * Toute entité métier multi-tenant doit implémenter cette interface :
 *   - directement (cas simple : l'entité a une colonne club_id, ex. Equipe, Joueur)
 *   - en délégant (cas indirect : l'entité tient son club via une autre entité,
 *     ex. Presence qui passe par sa Seance ou sa Rencontre)
 */
interface ClubAwareInterface
{
    /**
     * Retourne le Club auquel cette entité appartient.
     *
     * @return Club|null null uniquement si l'entité est en cours de construction
     *                   (entre new MaClasse() et le premier setClub()).
     *                   En BDD, ce ne devrait jamais être null pour les entités
     *                   métier (contrainte club_id NOT NULL).
     */
    public function getClub(): ?Club;
}
