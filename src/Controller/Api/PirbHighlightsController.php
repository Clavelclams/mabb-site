<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\JoueurRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbHighlightsController — [Dé-mock, 26/07/2026] le feed Scouting devient
 * RÉEL : dernier mock de données (7e sur 7 identifiés, avec la carte qui
 * attend son brainstorm produit).
 *
 *   GET /api/pirb/highlights → HighlightPost[] (contrat types/pirb.ts)
 *
 * LA SOURCE : Joueur.highlights existe depuis la V1.2c ({url, titre, date},
 * 5 max, gérés par la joueuse sur /profil/highlights côté web — l'app y
 * accède déjà en un tap via le SSO). Cet endpoint ne fait QUE lire.
 *
 * QUI APPARAÎT DANS LE FEED (règles, dans l'ordre) :
 *  1. Périmètre CLUB uniquement — même RGPD que la commu (public mineur).
 *  2. La joueuse a rendu ses highlights visibles : profil public scouting
 *     (opt-in V1.2c) OU réglage fin `highlightsPublics` de l'app. Défaut
 *     tout privé → rien ne fuit sans un choix explicite.
 *  3. Exception : la joueuse connectée voit TOUJOURS ses propres highlights
 *     (feedback immédiat : « je poste → je me vois »), même privés.
 *
 * SÉCURITÉ DES LIENS : le setter valide juste « c'est une URL ». Ici on
 * ajoute une LISTE BLANCHE de domaines (YouTube, Instagram, TikTok) : le
 * feed d'une app pour mineures n'affichera jamais un lien vers autre chose.
 * La plateforme du contrat est DÉRIVÉE du domaine (pas stockée).
 */
class PirbHighlightsController extends AbstractController
{
    private const LIMITE_FEED = 50;

    public function __construct(
        private readonly JoueurRepository $joueurRepo,
    ) {}

    #[Route('/api/pirb/highlights', name: 'api_pirb_highlights', methods: ['GET'])]
    public function feed(): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $club = $moi->getClub();
        if ($club === null) {
            return new JsonResponse([]);
        }

        $posts = [];
        foreach ($this->joueurRepo->findByClub($club->getId()) as $j) {
            if (!$j instanceof Joueur || !$j->isActive()) { continue; }

            $highlights = $j->getHighlights();
            if ($highlights === null || $highlights === []) { continue; }

            // Visibilité : mes propres highlights toujours ; ceux des autres
            // seulement si elles ont OUVERT (profil public OU réglage app).
            $estMoi = $j->getId() === $moi->getId();
            if (!$estMoi) {
                $conf = $j->getConfidentialite() ?? [];
                $ouvert = $j->isProfilPublic() || (($conf['highlightsPublics'] ?? false) === true);
                if (!$ouvert) { continue; }
            }

            $pseudo = trim(($j->getPrenom() ?? '') . ' ' . ($j->getNom() ?? ''));
            foreach ($highlights as $idx => $h) {
                $plateforme = $this->plateformeDe((string) ($h['url'] ?? ''));
                if ($plateforme === null) { continue; } // domaine hors liste blanche : jamais dans le feed

                $posts[] = [
                    // Id stable et sans table : joueuse + position dans sa liste.
                    'id'         => 'hl-' . $j->getId() . '-' . $idx,
                    'joueur'     => ['id' => $j->getId(), 'pseudo' => $pseudo, 'club' => $club->getNom()],
                    'titre'      => (string) ($h['titre'] ?? 'Highlight'),
                    'plateforme' => $plateforme,
                    'url'        => (string) $h['url'],
                    'date'       => (string) ($h['date'] ?? ''),
                ];
            }
        }

        // Plus récents d'abord ; les dates vides tombent en fin de liste.
        usort($posts, static fn(array $a, array $b) => strcmp($b['date'], $a['date']));

        return new JsonResponse(array_slice($posts, 0, self::LIMITE_FEED));
    }

    /**
     * Le domaine décide de la plateforme — et de l'ADMISSION dans le feed.
     * Sous-domaines acceptés (www., m., vm.tiktok…) via le test « se termine
     * par » sur l'hôte, jamais un simple str_contains (sinon
     * `youtube.com.evil.fr` passerait).
     */
    private function plateformeDe(string $url): ?string
    {
        $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($hote === '') { return null; }
        $finitPar = static fn(string $domaine): bool =>
            $hote === $domaine || str_ends_with($hote, '.' . $domaine);

        if ($finitPar('youtube.com') || $finitPar('youtu.be')) { return 'youtube'; }
        if ($finitPar('instagram.com')) { return 'instagram'; }
        if ($finitPar('tiktok.com')) { return 'tiktok'; }
        return null;
    }

    /** Même helper que les autres contrôleurs Api (trait dû — dette notée doc 18). */
    private function joueurOu404(): Joueur|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié.'], Response::HTTP_UNAUTHORIZED);
        }
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            return new JsonResponse(
                ['error' => 'Aucune fiche joueuse liée à ce compte. Contacte le staff du club.'],
                Response::HTTP_NOT_FOUND
            );
        }
        return $joueur;
    }
}
