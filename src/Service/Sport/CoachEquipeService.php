<?php

declare(strict_types=1);

namespace App\Service\Sport;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\CoachEquipeRepository;

/**
 * B5 — Service utilitaire pour les relations Coach ↔ Équipe.
 *
 * Réutilisé par :
 *   - B13 visibilité 5 paliers : "Seul mon coach voit X" → est-ce qu'on
 *     est dans le cas où le user connecté est coach de l'équipe du joueur ?
 *   - Manager dashboard coach : afficher les équipes coachées
 *   - Filtre séances coach : "Mes séances" = celles des équipes coachées
 */
class CoachEquipeService
{
    public function __construct(
        private readonly CoachEquipeRepository $coachEquipeRepo,
    ) {}

    /**
     * Le user $coach est-il LE coach (ou un assistant) de $joueur ?
     * Renvoie false si le joueur n'a pas d'équipe.
     */
    public function estMonCoach(User $coach, Joueur $joueur): bool
    {
        $equipe = $joueur->getEquipe();
        if ($equipe === null) {
            return false;
        }
        return $this->coachEquipeRepo->estCoachDeEquipe($coach, $equipe);
    }
}
