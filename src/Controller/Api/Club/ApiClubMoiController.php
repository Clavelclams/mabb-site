<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\ParentJoueurRepository;
use App\Service\SaisonService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-1 04/08/2026] GET /api/club/moi — le point d'entrée de l'app Venaball Club.
 *
 * CE QUE L'APP EN FAIT :
 *   C'est le premier appel après la connexion. Il répond à trois questions
 *   dont dépend TOUTE la navigation :
 *     1. Dans quels clubs suis-je ? (l'app affiche un sélecteur si > 1)
 *     2. Quelles VUES puis-je ouvrir ? (le switch « à la GTA » du cadrage :
 *        mode coach / parent / bénévole / joueuse)
 *     3. Sur laquelle ouvrir par défaut ?
 *
 * POURQUOI DES « VUES » ET PAS LES RÔLES BRUTS :
 *   Les rôles métier sont neuf (DIRIGEANT, COACH, STAFF, TRESORIER…), mais
 *   l'app n'a pas besoin de neuf écrans d'accueil. On projette les rôles sur
 *   quatre vues utiles sur mobile. Un dirigeant qui coache une équipe voit
 *   les deux vues ; un trésorier n'a rien à faire sur mobile aujourd'hui et
 *   reste sur le web (assumé, cf. cadrage doc 32 : « le web reste le filet
 *   complet »).
 *
 *   Cette projection est faite CÔTÉ SERVEUR, volontairement : c'est le serveur
 *   qui sait qui a le droit de quoi. L'app ne fait qu'afficher ce qu'on lui
 *   dit — elle ne déduit jamais un droit toute seule.
 *
 * VUE PAR DÉFAUT :
 *   Le cadrage laissait la question ouverte (« ouvrir sur la vue la plus
 *   pertinente du moment, ou laisser choisir ? »). Choix retenu ici : on
 *   ouvre sur la vue la plus « engageante » disponible (coach > bénévole >
 *   parent > joueuse), sans tenir compte du jour de la semaine. Ouvrir un
 *   écran différent selon le jour désoriente plus qu'il n'aide, et personne
 *   ne l'a encore testé sur le terrain. À revoir après les premiers retours.
 */
final class ApiClubMoiController extends ApiClubController
{
    /** Les vues possibles de l'app, par ordre de priorité d'ouverture. */
    private const VUES_ORDONNEES = ['coach', 'benevole', 'parent', 'joueuse'];

    public function __construct(
        private readonly SaisonService $saisonService,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly ParentJoueurRepository $parentJoueurRepository,
    ) {}

    #[Route('/api/club/moi', name: 'api_club_moi', methods: ['GET'])]
    public function moi(Request $request): JsonResponse
    {
        $user = $this->utilisateur();
        $parClub = $this->rolesParClub($user);

        // [VC-7 bugfix] Super-admin : aucun UserClubRole, mais accès à tous
        // les clubs (même règle que le web). On lui fabrique la même
        // structure que pour un membre normal, avec un rôle synthétique
        // DIRIGEANT : c'est ce que le reste de l'API lui accorde déjà
        // (equipesAccessibles, exigerEncadrement via le court-circuit).
        if ($parClub === [] && $this->estSuperAdmin($user)) {
            foreach ($this->clubRepository->findBy([], ['nom' => 'ASC']) as $club) {
                $parClub[(int) $club->getId()] = [
                    'club'  => $club,
                    'roles' => [UserClubRole::ROLE_DIRIGEANT],
                ];
            }
        }

        if ($parClub === []) {
            return $this->erreur(
                "Ton compte n'est rattaché à aucun club. Demande au club de t'ajouter.",
                403
            );
        }

        // Le club de travail : en-tête X-Club-Id, ou l'unique club, ou le premier.
        // On ne lève PAS d'erreur en cas de multi-club ici : cet endpoint sert
        // justement à découvrir la liste, l'app ne peut pas encore savoir quoi
        // envoyer. Les autres endpoints, eux, sont stricts.
        $demande = (int) $request->headers->get(self::HEADER_CLUB, '0');
        $clubCourant = isset($parClub[$demande])
            ? $parClub[$demande]['club']
            : reset($parClub)['club'];

        $saison = $this->saisonService->getSaisonActive();

        $clubs = [];
        foreach ($parClub as $entree) {
            $club = $entree['club'];
            $vues = $this->vuesDisponibles($user, $club, $entree['roles'], $saison);
            $clubs[] = [
                'id'     => $club->getId(),
                'nom'    => $club->getNom(),
                'roles'  => $entree['roles'],
                'vues'   => $vues,
                'courant' => $club->getId() === $clubCourant->getId(),
            ];
        }

        $vuesCourantes = [];
        foreach ($clubs as $c) {
            if ($c['courant']) {
                $vuesCourantes = $c['vues'];
                break;
            }
        }

        return new JsonResponse([
            'utilisateur' => [
                'id'     => $user->getId(),
                'prenom' => $user->getPrenom(),
                'nom'    => $user->getNom(),
                'email'  => $user->getEmail(),
            ],
            'saison'      => $saison,
            'clubs'       => $clubs,
            'clubCourant' => $clubCourant->getId(),
            'vueParDefaut' => $this->vueParDefaut($vuesCourantes),
        ]);
    }

    /**
     * Projette les rôles métier du compte sur les vues de l'app.
     *
     * Une vue n'est proposée que si elle a du CONTENU à afficher :
     *   - coach   : rôle COACH/DIRIGEANT/STAFF **et** au moins une équipe
     *               encadrée cette saison. Un dirigeant qui n'entraîne
     *               personne n'a pas de vue coach — elle serait vide.
     *   - parent  : rôle PARENT **et** au moins un lien enfant actif.
     *   - joueuse : une fiche Joueur existe pour ce compte dans ce club.
     *   - benevole: tout membre du club. C'est la vue « vie du club »
     *               (événements, missions), utile à tout le monde.
     *
     * @param string[] $roles
     * @return string[]
     */
    private function vuesDisponibles(User $user, Club $club, array $roles, string $saison): array
    {
        $vues = [];

        $estDirigeant = in_array(UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || $this->estSuperAdmin($user);
        $encadrant = $estDirigeant || array_intersect($roles, [
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
        ]) !== [];

        // [VC-7 bugfix] Un DIRIGEANT (et le super-admin) voit TOUTES les
        // équipes du club sans lien CoachEquipe : sa vue coach existe dès
        // que le club a une équipe active. Le lien CoachEquipe ne borne
        // que les coachs et le staff.
        $aDesEquipes = $estDirigeant
            ? $this->equipeRepository->count(['club' => $club, 'saison' => $saison, 'isActive' => true]) > 0
            : $this->compteEquipesEncadrees($user, $club, $saison) > 0;

        if ($encadrant && $aDesEquipes) {
            $vues[] = 'coach';
        }

        // Tout membre du club accède à la vie associative
        $vues[] = 'benevole';

        if (in_array(UserClubRole::ROLE_PARENT, $roles, true)
            && $this->aDesEnfantsDansLeClub($user, $club)) {
            $vues[] = 'parent';
        }

        if ($this->aUneFicheJoueuse($user, $club)) {
            $vues[] = 'joueuse';
        }

        // On renvoie dans l'ordre de priorité, pas dans l'ordre de découverte :
        // l'app peut ainsi afficher les onglets tels quels.
        return array_values(array_filter(
            self::VUES_ORDONNEES,
            static fn(string $v) => in_array($v, $vues, true)
        ));
    }

    /** @param string[] $vues */
    private function vueParDefaut(array $vues): string
    {
        foreach (self::VUES_ORDONNEES as $v) {
            if (in_array($v, $vues, true)) {
                return $v;
            }
        }
        return 'benevole';
    }

    /**
     * Nombre d'équipes que ce compte encadre dans CE club, cette saison.
     * Le filtre club est indispensable : findByCoach() ne le fait pas, et un
     * coach multi-club verrait sinon les équipes d'un autre club.
     */
    private function compteEquipesEncadrees(User $user, Club $club, string $saison): int
    {
        $liens = $this->coachEquipeRepository->findByCoach($user, $saison);
        $n = 0;
        foreach ($liens as $lien) {
            if ($lien->getEquipe()?->getClub()?->getId() === $club->getId()) {
                $n++;
            }
        }
        return $n;
    }

    /** Au moins un lien parent-enfant ACTIF sur une joueuse de ce club. */
    private function aDesEnfantsDansLeClub(User $user, Club $club): bool
    {
        $liens = $this->parentJoueurRepository->findBy(['parentUser' => $user]);
        foreach ($liens as $lien) {
            if (!$lien->isActive()) {
                continue;
            }
            if ($lien->getJoueur()?->getClub()?->getId() === $club->getId()) {
                return true;
            }
        }
        return false;
    }

    private function aUneFicheJoueuse(User $user, Club $club): bool
    {
        $joueur = $this->joueurRepository->findOneBy(['user' => $user, 'club' => $club]);
        return $joueur !== null;
    }
}
