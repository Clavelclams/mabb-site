<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\ReunionConvocation;
use App\Feed\FeedItem;
use App\Repository\Core\UserClubRoleRepository;
use App\Repository\Sport\ReunionConvocationRepository;
use App\Repository\Sport\ReunionRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Agrège les éléments du feed "Pour toi" du dashboard manager.
 *
 * RESPONSABILITÉ UNIQUE (SRP) : transformer plusieurs sources hétérogènes
 * (réunions à venir, PV non lus, réunions tenues, etc.) en une LISTE UNIFORME
 * de FeedItem, triée et limitée, prête à être affichée.
 *
 * Pourquoi un service à part et pas dans le controller ?
 *   - Le controller ne doit pas savoir COMMENT on construit le feed.
 *     Il doit juste l'appeler et passer le résultat à la vue.
 *   - On peut tester le service indépendamment (PHPUnit futur).
 *   - Phase 2 : on étend ce service (algo de pertinence par rôle) sans toucher
 *     au controller.
 *
 * Pourquoi UrlGeneratorInterface ?
 *   - Le service génère les liens des items via les NOMS de routes
 *     ('manager_reunion_show'). Si on renomme une route, on met à jour ici
 *     et nulle part ailleurs. Pas d'URL hardcodée.
 *
 * PHASE 1 (actuel) — collecte 3 sources :
 *   1. Prochaine réunion à venir où l'user est convoqué
 *   2. Dernière réunion tenue dont la synthèse lui est visible
 *   3. Dernier PV publié non lu par l'user
 *
 * PHASE 2 (futur) — extension prévue :
 *   - Filtre par rôle (COACH → activités équipe, PARENT → enfant, etc.)
 *   - Score de pertinence (proximité date + lien avec user)
 *   - Pagination / load more
 */
final class FeedAggregator
{
    public function __construct(
        private readonly ReunionConvocationRepository $convocationRepository,
        private readonly ReunionRepository $reunionRepository,
        private readonly UserClubRoleRepository $userClubRoleRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Construit le feed pour un user dans le contexte d'un club.
     *
     * @return FeedItem[] Liste triée par date desc (récent en premier).
     *                    Vide si aucune source ne produit d'item.
     */
    public function buildForUser(User $user, Club $club): array
    {
        $items = [];

        // ====================================================================
        // SOURCE 1 — Prochaine réunion à venir où l'user est convoqué
        // ====================================================================
        // On prend la PROCHAINE convocation chronologique pour ne pas spammer
        // le feed si l'user en a 5. La logique "voir toutes ses réunions" est
        // dans la page /reunions.
        $prochaineConv = $this->getProchaineConvocation($user, $club);
        if ($prochaineConv !== null) {
            $reu = $prochaineConv->getReunion();
            $items[] = new FeedItem(
                type:      'reunion_a_venir',
                date:      $reu->getDate(),
                titre:     $reu->getTitre(),
                sousTitre: sprintf(
                    'Convocation — %s%s',
                    $reu->getDate()->format('d/m à H:i'),
                    $reu->getLieu() ? ' · ' . $reu->getLieu() : ''
                ),
                lien:      $this->urlGenerator->generate('manager_reunion_show', ['id' => $reu->getId()]),
                icone:     'bi-calendar-event-fill',
                couleur:   '#3b82f6', // bleu — action future
                labelType: 'Réunion à venir',
            );
        }

        // ====================================================================
        // SOURCE 2 — Dernière réunion TENUE dont la synthèse est visible
        // ====================================================================
        // Récupère les rôles actifs de l'user dans ce club pour le filtre fin
        // (synthèse publiée à STAFF uniquement, ou à tout le monde, etc.)
        $rolesActifs = $this->getRolesActifs($user, $club);
        $derniereTenue = $this->reunionRepository->findDerniereTenueVisibleA($club, $rolesActifs);
        if ($derniereTenue !== null) {
            $items[] = new FeedItem(
                type:      'reunion_tenue',
                date:      $derniereTenue->getDate(),
                titre:     'Synthèse : ' . $derniereTenue->getTitre(),
                sousTitre: sprintf(
                    'Réunion du %s — synthèse disponible',
                    $derniereTenue->getDate()->format('d/m/Y')
                ),
                lien:      $this->urlGenerator->generate('manager_reunion_show', ['id' => $derniereTenue->getId()]),
                icone:     'bi-file-text-fill',
                couleur:   '#10b981', // vert — info accessible
                labelType: 'Synthèse de réunion',
            );
        }

        // ====================================================================
        // SOURCE 3 — Prochaine réunion PUBLIQUE du club (visiblePourTous = true)
        // ====================================================================
        // Annoncée à TOUS les CLUB_MEMBER même sans convocation nominative.
        // On évite le doublon avec SOURCE 1 : si l'user est déjà convoqué à
        // la même réunion, on n'affiche pas une 2e fois.
        $reunionPublique = $this->reunionRepository->findProchainePublique($club);
        if ($reunionPublique !== null
            && ($prochaineConv === null || $prochaineConv->getReunion()?->getId() !== $reunionPublique->getId())
        ) {
            $items[] = new FeedItem(
                type:      'reunion_publique',
                date:      $reunionPublique->getDate(),
                titre:     $reunionPublique->getTitre(),
                sousTitre: sprintf(
                    'Ouverte à tous — %s%s',
                    $reunionPublique->getDate()->format('d/m à H:i'),
                    $reunionPublique->getLieu() ? ' · ' . $reunionPublique->getLieu() : ''
                ),
                lien:      $this->urlGenerator->generate('manager_reunion_show', ['id' => $reunionPublique->getId()]),
                icone:     'bi-megaphone-fill',
                couleur:   '#ea580c', // orange — annonce publique
                labelType: 'Annonce du club',
            );
        }

        // ====================================================================
        // SOURCE 4 — Dernier PV non lu (notification)
        // ====================================================================
        // findPvNonLus renvoie toutes les convocations avec PV non lu, on prend
        // la plus récente (= la première de la liste car triée par date desc).
        $pvNonLus = $this->convocationRepository->findPvNonLus($user, $club);
        if (count($pvNonLus) > 0) {
            $convPv = $pvNonLus[0];
            $reuPv = $convPv->getReunion();
            $items[] = new FeedItem(
                type:      'pv_non_lu',
                date:      $reuPv->getDate(),
                titre:     'Nouveau PV : ' . $reuPv->getTitre(),
                sousTitre: sprintf(
                    'PV publié pour la réunion du %s',
                    $reuPv->getDate()->format('d/m/Y')
                ),
                lien:      $this->urlGenerator->generate('manager_reunion_show', ['id' => $reuPv->getId()]),
                icone:     'bi-file-earmark-text-fill',
                couleur:   '#f59e0b', // orange — action à faire (lire)
                labelType: 'PV à lire',
            );
        }

        // ====================================================================
        // TRI : par date décroissante (récent en premier).
        // Le PHP usort est stable depuis 8.0 → pas de surprise d'ordre entre
        // items partageant la même date.
        // ====================================================================
        usort($items, static function (FeedItem $a, FeedItem $b): int {
            return $b->date <=> $a->date;
        });

        return $items;
    }

    /**
     * Trouve la prochaine convocation (chronologique) où l'user est attendu.
     *
     * Note : la méthode findMesReunionsAVenir() existe déjà et renvoie une liste
     * triée ASC → on prend simplement le premier élément. Si la méthode change
     * un jour son tri, on reverra ça ici.
     */
    private function getProchaineConvocation(User $user, Club $club): ?ReunionConvocation
    {
        $list = $this->convocationRepository->findMesReunionsAVenir($user, $club);
        return $list[0] ?? null;
    }

    /**
     * Liste des codes de rôles actifs de l'user dans ce club.
     *
     * Pourquoi ici et pas dans le controller ?
     *   - Le controller ne doit pas connaître les détails de filtrage de visibilité.
     *   - Cette logique sera réutilisée en Phase 2 pour scorer la pertinence.
     *
     * @return string[]
     */
    private function getRolesActifs(User $user, Club $club): array
    {
        $userId = $user->getId();
        $clubId = $club->getId();
        if ($userId === null || $clubId === null) {
            return [];
        }

        $ucrs = $this->userClubRoleRepository->findActiveRolesForUserInClub($userId, $clubId);
        $codes = [];
        foreach ($ucrs as $ucr) {
            $codes[] = $ucr->getRole();
        }
        return $codes;
    }
}
