<?php

declare(strict_types=1);

namespace App\Controller\Api\Club;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\Equipe;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\CoachEquipeRepository;
use App\Repository\Sport\EquipeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\RencontreRepository;
use App\Service\SaisonService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [VC-2 04/08/2026] La vue COACH de l'app Venaball Club.
 *
 * PÉRIMÈTRE V1, volontairement étroit :
 *   « le coach voit ses équipes et ses prochains matchs depuis son téléphone ».
 *   La convocation et le pointage viendront ensuite (VC-3), en s'appuyant sur
 *   ce socle. Faire tout d'un coup, c'est six mois ; faire ça, c'est utile
 *   tout de suite et testable au gymnase.
 *
 * LA RÈGLE D'ISOLATION, ICI, EST DOUBLE :
 *   1. le club, résolu et vérifié par ApiClubController ;
 *   2. les ÉQUIPES RÉELLEMENT ENCADRÉES par ce compte (CoachEquipe), et pas
 *      « toutes les équipes du club ».
 *   Un coach de U13 n'a rien à faire dans l'effectif des Séniors : ce sont
 *   des données personnelles de mineures, le besoin d'en connaître s'arrête
 *   à ses propres équipes.
 *   Exception assumée : un DIRIGEANT voit toutes les équipes du club — c'est
 *   son rôle, et il l'a déjà sur le web.
 */
final class ApiClubCoachController extends ApiClubController
{
    public function __construct(
        private readonly SaisonService $saisonService,
        private readonly CoachEquipeRepository $coachEquipeRepository,
        private readonly EquipeRepository $equipeRepository,
        private readonly RencontreRepository $rencontreRepository,
        private readonly JoueurRepository $joueurRepository,
    ) {}

    /**
     * GET /api/club/equipes — les équipes que je peux encadrer, cette saison.
     *
     * Réponse : [{id, nom, categorie, roleCoach, nbJoueuses}]
     * `roleCoach` vaut PRINCIPAL, ASSISTANT, ou DIRIGEANT (accès par le rôle,
     * pas par un lien CoachEquipe) — l'app peut nuancer l'affichage.
     */
    #[Route('/api/club/equipes', name: 'api_club_equipes', methods: ['GET'])]
    public function equipes(Request $request): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $saison = $this->saisonService->getSaisonActive();
        $equipes = $this->equipesAccessibles($this->utilisateur(), $club, $saison);

        $sortie = [];
        foreach ($equipes as $item) {
            /** @var Equipe $equipe */
            $equipe = $item['equipe'];
            $sortie[] = [
                'id'         => $equipe->getId(),
                'nom'        => $equipe->getNom(),
                'categorie'  => $equipe->getCategorie(),
                'saison'     => $equipe->getSaison(),
                'roleCoach'  => $item['role'],
                'nbJoueuses' => $this->joueurRepository->count([
                    'equipe' => $equipe, 'isActive' => true, 'isTemporaire' => false,
                ]),
            ];
        }

        return new JsonResponse([
            'saison'  => $saison,
            'equipes' => $sortie,
        ]);
    }

    /**
     * GET /api/club/rencontres — les rencontres de MES équipes.
     *
     * Paramètres :
     *   ?equipe=<id>    limite à une équipe (doit être l'une des miennes)
     *   ?periode=avenir (défaut) | passees | toutes
     *   ?limite=<n>     20 par défaut, 50 au maximum
     *
     * Tri : les prochaines d'abord pour « avenir », les plus récentes d'abord
     * sinon. C'est ce qu'un coach veut voir en ouvrant l'app.
     */
    #[Route('/api/club/rencontres', name: 'api_club_rencontres', methods: ['GET'])]
    public function rencontres(Request $request): JsonResponse
    {
        $club = $this->clubCourant($request);
        $this->exigerEncadrement($club);

        $saison = $this->saisonService->getSaisonActive();
        $equipes = $this->equipesAccessibles($this->utilisateur(), $club, $saison);

        // Whitelist des équipes : c'est ELLE qui borne la requête, jamais un
        // identifiant venu du client.
        $idsAutorises = array_map(
            static fn(array $i) => (int) $i['equipe']->getId(),
            $equipes
        );

        if ($idsAutorises === []) {
            return new JsonResponse(['saison' => $saison, 'rencontres' => []]);
        }

        $filtreEquipe = (int) $request->query->get('equipe', 0);
        if ($filtreEquipe > 0) {
            if (!in_array($filtreEquipe, $idsAutorises, true)) {
                // 404 et non 403 : on ne confirme pas l'existence d'une équipe
                // qui n'est pas la sienne.
                return $this->erreur('Équipe introuvable.', 404);
            }
            $idsAutorises = [$filtreEquipe];
        }

        $periode = (string) $request->query->get('periode', 'avenir');
        $limite = max(1, min(50, (int) $request->query->get('limite', 20)));

        $qb = $this->rencontreRepository->createQueryBuilder('r')
            ->join('r.equipe', 'e')->addSelect('e')
            ->where('e.id IN (:equipes)')
            ->setParameter('equipes', $idsAutorises)
            ->setMaxResults($limite);

        $maintenant = new \DateTimeImmutable('today');
        if ($periode === 'avenir') {
            $qb->andWhere('r.date >= :d')->setParameter('d', $maintenant)
               ->orderBy('r.date', 'ASC');
        } elseif ($periode === 'passees') {
            $qb->andWhere('r.date < :d')->setParameter('d', $maintenant)
               ->orderBy('r.date', 'DESC');
        } else {
            $qb->orderBy('r.date', 'DESC');
        }

        $sortie = [];
        /** @var Rencontre $r */
        foreach ($qb->getQuery()->getResult() as $r) {
            $sortie[] = [
                'id'         => $r->getId(),
                'date'       => $r->getDate()?->format(\DateTimeInterface::ATOM),
                'equipe'     => [
                    'id'  => $r->getEquipe()?->getId(),
                    'nom' => $r->getEquipe()?->getNom(),
                ],
                'adversaire' => $r->getAdversaire(),
                'domicile'   => $r->isDomicile(),
                'lieu'       => $r->getLieu(),
                'statut'     => $r->getStatut(),
                'type'       => $r->getTypeRencontre(),
                'score'      => [
                    'nous'    => $r->getScoreEquipe(),
                    'adverse' => $r->getScoreAdverse(),
                ],
            ];
        }

        return new JsonResponse([
            'saison'     => $saison,
            'periode'    => $periode,
            'rencontres' => $sortie,
        ]);
    }

    // ====================================================================
    // PRIVÉ
    // ====================================================================

    /**
     * Les équipes que ce compte peut encadrer dans ce club, cette saison.
     *
     * Deux chemins d'accès, jamais mélangés :
     *   - DIRIGEANT : toutes les équipes actives du club (c'est son périmètre) ;
     *   - COACH / STAFF : uniquement celles liées par CoachEquipe.
     *
     * @return array<int, array{equipe: Equipe, role: string}>
     */
    private function equipesAccessibles(User $user, Club $club, string $saison): array
    {
        $roles = $this->rolesParClub($user)[(int) $club->getId()]['roles'] ?? [];
        $estDirigeant = in_array(\App\Entity\Core\UserClubRole::ROLE_DIRIGEANT, $roles, true)
            || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);

        if ($estDirigeant) {
            $equipes = $this->equipeRepository->findBy(
                ['club' => $club, 'saison' => $saison, 'isActive' => true],
                ['nom' => 'ASC']
            );

            return array_map(
                static fn(Equipe $e) => ['equipe' => $e, 'role' => 'DIRIGEANT'],
                $equipes
            );
        }

        $resultat = [];
        foreach ($this->coachEquipeRepository->findByCoach($user, $saison) as $lien) {
            $equipe = $lien->getEquipe();
            // Filtre club indispensable : findByCoach() ne le fait pas, et un
            // coach de deux clubs verrait sinon les équipes de l'autre.
            if ($equipe === null || $equipe->getClub()?->getId() !== $club->getId()) {
                continue;
            }
            $resultat[] = ['equipe' => $equipe, 'role' => $lien->getRoleCoach()];
        }

        return $resultat;
    }
}
