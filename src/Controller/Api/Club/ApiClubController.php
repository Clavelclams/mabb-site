<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * [VC-1 04/08/2026] Socle commun de l'API Venaball Club (app staff/famille).
 *
 * POURQUOI UNE FAMILLE D'API SÉPARÉE DE /api/pirb :
 *   `/api/pirb/*` est l'API de l'app JOUEUSE. Tous ses endpoints commencent
 *   par « retrouve la fiche Joueur de ce compte, sinon 404 ». Un coach n'a
 *   pas de fiche joueuse : il ne peut rien en tirer.
 *   `/api/club/*` part de l'autre bout : « ce compte, dans CE club, avec CE
 *   rôle, a-t-il le droit de faire ça ? »
 *
 * LE PROBLÈME QUE RÉSOUT CETTE CLASSE :
 *   Un même compte peut être coach au club A et parent au club B (c'est tout
 *   l'intérêt de UserClubRole). L'app doit donc TOUJOURS dire dans quel club
 *   elle travaille. On le lit dans l'en-tête `X-Club-Id`, et on le vérifie :
 *   un identifiant de club envoyé par le client n'est JAMAIS de confiance.
 *
 * RÈGLE DE SÉCURITÉ (la plus importante du projet) :
 *   Aucune requête métier ne part sans être bornée au club résolu ici. Trois
 *   fuites de données de mineures ont déjà été trouvées faute de ce filtre
 *   (audit du 26/07, doc 34). Passer par clubCourant() et exigerRole().
 *
 * CONVENTION DE RÉPONSE :
 *   Succès → le corps utile directement (pas d'enveloppe « data »).
 *   Erreur  → {"error": "message lisible"} + code HTTP juste.
 *   On ne renvoie jamais 403 pour dire « ça existe mais pas pour toi » sur
 *   une ressource d'un autre club : on renvoie 404, pour ne pas confirmer
 *   son existence.
 */
abstract class ApiClubController extends AbstractController
{
    /** En-tête par lequel l'app déclare le club sur lequel elle travaille. */
    public const HEADER_CLUB = 'X-Club-Id';

    /**
     * [VC-7 bugfix] Injecté par setter (la classe est abstraite, les enfants
     * ont chacun leur constructeur — l'attribut #[Required] évite de le
     * répéter dans chacun). Sert au cas SUPER-ADMIN ci-dessous.
     */
    protected ClubRepository $clubRepository;

    #[Required]
    public function setClubRepository(ClubRepository $clubRepository): void
    {
        $this->clubRepository = $clubRepository;
    }

    protected \App\Repository\Sport\EquipeRepository $equipeRepositorySocle;

    #[Required]
    public function setEquipeRepositorySocle(\App\Repository\Sport\EquipeRepository $r): void
    {
        $this->equipeRepositorySocle = $r;
    }

    /**
     * [VC-8 bugfix] La saison à utiliser pour les ÉQUIPES de ce club.
     *
     * Le bug trouvé par Clavel : la saison active est 2026-2027 (bascule au
     * 1er juillet), mais le passage de saison n'a pas encore été appliqué —
     * les équipes du club sont toujours en 2025-2026. L'API cherchait des
     * équipes 2026-2027, en trouvait zéro, et concluait « pas de vue coach ».
     *
     * Règle : la saison active si elle a des équipes, sinon la plus récente
     * qui en a. L'app montre TOUJOURS quelque chose de vrai, et le jour où
     * le passage de saison est appliqué, elle bascule toute seule.
     */
    protected function saisonAvecEquipes(Club $club, string $saisonActive): string
    {
        $nb = $this->equipeRepositorySocle->count([
            'club' => $club, 'saison' => $saisonActive, 'isActive' => true,
        ]);
        if ($nb > 0) {
            return $saisonActive;
        }

        // La plus récente saison du club qui a des équipes actives.
        // Le format AAAA-AAAA se trie alphabétiquement = chronologiquement.
        $saisons = $this->equipeRepositorySocle->saisonsDisponibles($club);
        rsort($saisons);
        foreach ($saisons as $s) {
            if (is_string($s) && $s !== '') {
                return $s;
            }
        }
        return $saisonActive;
    }

    /**
     * [VC-7 bugfix] Le super-admin n'a AUCUN UserClubRole : sur le web, le
     * ClubVoter le court-circuite, mais cette API raisonnait uniquement en
     * rôles par club → il recevait « aucun club » (bug trouvé par Clavel au
     * premier test Expo). Règle alignée sur le web : le super-admin voit
     * tous les clubs.
     */
    protected function estSuperAdmin(User $user): bool
    {
        return in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    /**
     * Le compte authentifié par le jeton Bearer.
     * Le firewall `api` garantit qu'il existe ; ce cast rend le typage explicite.
     */
    protected function utilisateur(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Ne devrait jamais arriver (access_control exige l'authentification)
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    /**
     * Les rôles ACTIFS du compte, groupés par club.
     *
     * Deux conditions cumulatives, et il faut les deux :
     *   - isStatusActive() : la demande de rattachement a été validée
     *   - isActive()       : le rôle n'a pas été désactivé depuis
     * Ne filtrer que sur l'une des deux est un piège classique du projet
     * (bug réel corrigé le 26/07 sur la recherche de parents).
     *
     * @return array<int, array{club: Club, roles: string[]}>
     */
    protected function rolesParClub(User $user): array
    {
        $parClub = [];
        foreach ($user->getUserClubRoles() as $ucr) {
            if (!$ucr->isActive() || !$ucr->isStatusActive()) {
                continue;
            }
            $club = $ucr->getClub();
            $role = $ucr->getRole();
            if ($club === null || $role === null) {
                continue;
            }
            $id = (int) $club->getId();
            if (!isset($parClub[$id])) {
                $parClub[$id] = ['club' => $club, 'roles' => []];
            }
            if (!in_array($role, $parClub[$id]['roles'], true)) {
                $parClub[$id]['roles'][] = $role;
            }
        }
        return $parClub;
    }

    /**
     * Le club sur lequel porte la requête.
     *
     * Résolution, dans l'ordre :
     *   1. en-tête `X-Club-Id` s'il est fourni ET que le compte y a un rôle actif ;
     *   2. sinon, si le compte n'appartient qu'à UN club, celui-là (confort :
     *      l'immense majorité des utilisateurs) ;
     *   3. sinon, erreur explicite demandant à l'app de choisir.
     *
     * @throws ApiClubException si le club est absent, inconnu ou interdit
     */
    protected function clubCourant(Request $request): Club
    {
        $user = $this->utilisateur();
        $parClub = $this->rolesParClub($user);

        // Super-admin : accès à tous les clubs, comme sur le web.
        if ($parClub === [] && $this->estSuperAdmin($user)) {
            $demande = (int) $request->headers->get(self::HEADER_CLUB, '0');
            if ($demande > 0) {
                $club = $this->clubRepository->find($demande);
                if ($club !== null) {
                    return $club;
                }
                throw new ApiClubException('Club introuvable.', Response::HTTP_NOT_FOUND);
            }
            $tous = $this->clubRepository->findBy([], ['nom' => 'ASC']);
            if (count($tous) === 1) {
                return $tous[0];
            }
            throw new ApiClubException(
                'Plusieurs clubs disponibles : précise lequel via l\'en-tête ' . self::HEADER_CLUB . '.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($parClub === []) {
            throw new ApiClubException(
                "Ton compte n'est rattaché à aucun club.",
                Response::HTTP_FORBIDDEN
            );
        }

        $demande = (int) $request->headers->get(self::HEADER_CLUB, '0');

        if ($demande > 0) {
            // Club inconnu du compte → 404 et pas 403 : on ne confirme pas
            // l'existence d'un club auquel l'utilisateur n'appartient pas.
            if (!isset($parClub[$demande])) {
                throw new ApiClubException('Club introuvable.', Response::HTTP_NOT_FOUND);
            }
            return $parClub[$demande]['club'];
        }

        if (count($parClub) === 1) {
            return reset($parClub)['club'];
        }

        throw new ApiClubException(
            'Plusieurs clubs disponibles : précise lequel via l\'en-tête ' . self::HEADER_CLUB . '.',
            Response::HTTP_BAD_REQUEST
        );
    }

    /**
     * Exige que le compte détienne l'un des rôles donnés dans ce club.
     *
     * On ne s'appuie pas sur le ClubVoter ici : le Voter raisonne sur des
     * ENTITÉS (« ce joueur », « cette rencontre »), alors qu'à ce stade on
     * valide seulement l'accès à une famille d'endpoints. Le Voter reste
     * utilisé, en plus, dès qu'on manipule une entité précise.
     *
     * @param string[] $roles constantes UserClubRole::ROLE_*
     * @throws ApiClubException
     */
    protected function exigerRole(Club $club, array $roles): void
    {
        $user = $this->utilisateur();

        // Le super-admin traverse tout (même court-circuit que le ClubVoter)
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $parClub = $this->rolesParClub($user);
        $mes = $parClub[(int) $club->getId()]['roles'] ?? [];

        if (array_intersect($roles, $mes) === []) {
            throw new ApiClubException(
                "Tu n'as pas les droits nécessaires dans ce club.",
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /** Raccourci : les rôles « encadrement » qui peuvent gérer une équipe. */
    protected function exigerEncadrement(Club $club): void
    {
        $this->exigerRole($club, [
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_STAFF,
        ]);
    }

    /** Réponse d'erreur normalisée. */
    protected function erreur(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['error' => $message], $code);
    }
}
