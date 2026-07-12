<?php

declare(strict_types=1);

namespace App\Service\Otm;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\AffectationMatchRepository;
use App\Repository\Sport\OtmInterdictionRepository;

/**
 * OtmService — [OTM V2, 12/07/2026]
 *
 * LA source unique des règles d'organisation du week-end. Le bouton « je
 * m'inscris », le kanban du dirigeant et l'auto-affectation du mercredi
 * passent TOUS par ici : impossible qu'une règle s'applique à un endroit et
 * pas à un autre.
 *
 * ─── LES RÈGLES ───────────────────────────────────────────────────────────
 *
 * 1. FENÊTRE D'INSCRIPTION (calculée, aucun champ en base) :
 *      ouverture = date de la rencontre − 7 jours
 *      fermeture = le mercredi 23h59 qui précède la rencontre
 *    Avant l'ouverture, personne ne peut se placer : sinon les malins
 *    réservent tout un mois à l'avance et raflent les postes peinards.
 *
 * 2. À LA FERMETURE (mercredi soir), les postes TITULAIRES encore vides sont
 *    attribués AU HASARD parmi le staff (cf. ROLES_POOL_AUTO). Le dirigeant
 *    peut toujours corriger ensuite.
 *
 * 3. TITULAIRE vs ASSISTANT :
 *      - titulaire : un seul par poste, c'est du STAFF, il supervise, il est
 *        auto-affecté s'il ne s'est pas placé.
 *      - assistant (« assisté de ») : bénévole / parent / joueuse en renfort.
 *        Se place librement, jamais auto-affecté, plusieurs possibles.
 *
 * 4. INTERDICTIONS : « X peut tout tenir sauf l'arbitrage ». Bloque
 *    l'auto-inscription, l'auto-affectation ET le glisser-déposer du dirigeant.
 *
 * 5. ANTI-RÉPÉTITION : pas plus de 2 fois le MÊME poste dans la MÊME journée.
 *    Sur 5 rencontres, tu ne feras pas 3 fois le chrono.
 */
class OtmService
{
    /** Combien de jours avant la rencontre les inscriptions ouvrent. */
    public const JOURS_OUVERTURE = 7;

    /** Maximum d'occurrences d'un même poste, pour une personne, dans une journée. */
    public const MAX_MEME_POSTE_PAR_JOUR = 2;

    /**
     * Qui peut être placé D'OFFICE le mercredi. Le staff uniquement : les
     * bénévoles, parents et joueuses se placent s'ils le veulent, mais on ne
     * leur impose rien. (Les services civiques sont rattachés au rôle STAFF.)
     */
    public const ROLES_POOL_AUTO = [
        UserClubRole::ROLE_STAFF,
        UserClubRole::ROLE_EMPLOYE,
    ];

    public function __construct(
        private readonly AffectationMatchRepository $affectationRepo,
        private readonly OtmInterdictionRepository $interdictionRepo,
    ) {
    }

    // ── Fenêtre ───────────────────────────────────────────────────────────

    /**
     * @return array{ouverture: ?\DateTimeImmutable, fermeture: ?\DateTimeImmutable, ouverte: bool, fermee: bool, pas_encore: bool}
     */
    public function fenetre(Rencontre $rencontre): array
    {
        $date = $rencontre->getDate();
        if ($date === null) {
            // Pas de date : on n'ouvre rien (on ne sait pas quand ça se joue).
            return ['ouverture' => null, 'fermeture' => null, 'ouverte' => false, 'fermee' => false, 'pas_encore' => true];
        }

        $jourMatch = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $ouverture = $jourMatch->modify('-' . self::JOURS_OUVERTURE . ' days');

        // Le mercredi qui précède la rencontre, 23h59.
        $fermeture = $jourMatch->modify('last wednesday')->setTime(23, 59, 59);
        // Garde-fou : si la rencontre tombe un mercredi ou un jeudi, le
        // « mercredi précédent » retombe avant l'ouverture → on ferme la
        // veille au soir plutôt que d'avoir une fenêtre vide.
        if ($fermeture <= $ouverture) {
            $fermeture = $jourMatch->modify('-1 day')->setTime(23, 59, 59);
        }

        $now = new \DateTimeImmutable();

        return [
            'ouverture'  => $ouverture,
            'fermeture'  => $fermeture,
            'ouverte'    => $now >= $ouverture && $now <= $fermeture,
            'fermee'     => $now > $fermeture,
            'pas_encore' => $now < $ouverture,
        ];
    }

    public function estOuverte(Rencontre $rencontre): bool
    {
        return $this->fenetre($rencontre)['ouverte'];
    }

    // ── Règles de placement ───────────────────────────────────────────────

    /**
     * Peut-on placer cette personne sur ce poste pour cette rencontre ?
     *
     * @param bool $assistant  true = renfort (« assisté de »), false = titulaire
     * @param bool $parAdmin   true = c'est le dirigeant qui place (il ignore la
     *                         fenêtre, mais PAS les interdictions ni l'anti-répétition)
     *
     * @return string|null  null = c'est bon. Sinon le motif du refus, à afficher.
     */
    public function motifRefus(
        Rencontre $rencontre,
        User $user,
        string $role,
        bool $assistant = false,
        bool $parAdmin = false,
    ): ?string {
        $club = $rencontre->getClub();
        if ($club === null) {
            return 'Rencontre sans club.';
        }
        if (!array_key_exists($role, AffectationMatch::ROLES)) {
            return 'Poste inconnu.';
        }

        // 1. Fenêtre (le dirigeant, lui, place quand il veut)
        if (!$parAdmin) {
            $f = $this->fenetre($rencontre);
            if ($f['pas_encore']) {
                return sprintf(
                    'Les inscriptions ouvrent le %s (7 jours avant la rencontre).',
                    $f['ouverture']?->format('d/m/Y') ?? '—'
                );
            }
            if ($f['fermee']) {
                return 'Les inscriptions sont closes (fermeture le mercredi soir). Vois avec un dirigeant.';
            }
        }

        // 2. Interdiction de poste
        if ($this->interdictionRepo->estInterdit($club, $user, $role)) {
            return sprintf('%s ne peut pas tenir le poste « %s » (poste interdit).',
                $user->getPrenom(), AffectationMatch::ROLES[$role]);
        }

        // 3. Poste titulaire déjà pris
        if (!$assistant) {
            $deja = $this->affectationRepo->findActiveByRencontreAndRole($rencontre, $role);
            if ($deja !== null && $deja->isTitulaire() && $deja->getUser()?->getId() !== $user->getId()) {
                return sprintf('Le poste « %s » est déjà tenu par %s.',
                    AffectationMatch::ROLES[$role], $deja->getPersonneNom());
            }
        }

        // 4. Anti-répétition : pas plus de 2× le même poste dans la journée
        $date = $rencontre->getDate();
        if ($date !== null) {
            $deja = $this->affectationRepo->compterMemePostePourJour($user, $role, $date, $rencontre);
            if ($deja >= self::MAX_MEME_POSTE_PAR_JOUR) {
                return sprintf(
                    '%s tient déjà %d fois le poste « %s » ce jour-là. Change de poste (max %d).',
                    $user->getPrenom(), $deja, AffectationMatch::ROLES[$role], self::MAX_MEME_POSTE_PAR_JOUR
                );
            }
        }

        return null;
    }

    /**
     * Les postes qu'une personne a le droit de tenir (interdictions retirées).
     *
     * @return array<string,string> [code => libellé]
     */
    public function postesAutorisesPour(Club $club, User $user): array
    {
        $interdits = $this->interdictionRepo->rolesInterditsPour($club, $user);

        return array_diff_key(AffectationMatch::ROLES, array_flip($interdits));
    }
}
