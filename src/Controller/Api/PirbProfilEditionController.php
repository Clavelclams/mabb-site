<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\BilanCompetence;
use App\Entity\Sport\Joueur;
use App\Repository\Pirb\SeancePlaygroundRepository;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbProfilEditionController — [Dé-mock, 13/07/2026] les 4 endpoints qui
 * rendent RÉELS les écrans profil/édition (jusqu'ici en mock en prod) :
 *
 *   GET   /api/pirb/attributs        → AttributsJoueur (échelle 0-20)
 *   PATCH /api/pirb/profil/bio       → { bio }
 *   GET   /api/pirb/confidentialite  → ConfidentialiteSettings
 *   PUT   /api/pirb/confidentialite  → ConfidentialiteSettings
 *
 * ── LE CALCUL DES ATTRIBUTS (la règle, écrite une fois pour toutes) ──────
 * Source n°1 : le DERNIER BILAN DE COMPÉTENCES VALIDÉ (notes coach sur 10,
 * BilanCompetenceRepository::findDernierValide). C'est la donnée crédible :
 * évaluée par un humain qualifié, jamais auto-déclarée (règle produit :
 * « personne ne peut se gonfler ses attributs à la main »).
 *   adresse  = moyenne(qttAdresse, qttEfficacitePanier)            × 2
 *   dribble  = qttAisance                                          × 2
 *   defense  = moyenne(qttDefense, qttRebondCatcher, qttRebondTransiter) × 2
 *   physique = moyenne(qpEnchainement, qpVitesse, qpSoinsDuCorps)  × 2
 * (moyennes en ignorant les notes absentes ; axe entièrement vide → base 10,
 * le neutre de l'échelle — ni flatteur ni punitif.)
 *
 * Source n°2 : le PLAYGROUND — le travail perso fait monter adresse (mode
 * tir) et dribble (mode dribble) : +1 point par tranche de 150 réussis
 * cumulés, plafonné à +2. Assez pour que « je m'entraîne → ma carte bouge »,
 * trop peu pour gonfler un attribut sans la validation du coach.
 * Le tout borné [0, 20]. Pas de bilan du tout → base 10 partout + bonus.
 *
 * ── CONFIDENTIALITÉ ──────────────────────────────────────────────────────
 * Stockée en JSON sur la fiche Joueur (colonne `confidentialite`, migration
 * Version20260713150000). RÈGLE D'OR : tout privé par défaut — l'absence de
 * réglage = tout à false. Le PUT ne lit QUE les 6 clés connues (une clé
 * inconnue est ignorée, pas stockée).
 */
class PirbProfilEditionController extends AbstractController
{
    /** Les 6 réglages du contrat types/pirb.ts::ConfidentialiteSettings. */
    private const CLES_CONFIDENTIALITE = [
        'statsPubliques', 'shotChartPublic', 'badgesPublics',
        'bioPublique', 'reseauxPublics', 'highlightsPublics',
    ];

    private const BIO_MAX = 500;

    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly BilanCompetenceRepository $bilanRepo,
        private readonly SeancePlaygroundRepository $playgroundRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/pirb/attributs
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/attributs', name: 'api_pirb_attributs', methods: ['GET'])]
    public function attributs(): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $bilan = $this->bilanRepo->findDernierValide($moi);

        // Socle coach (sur 10 → ×2 vers l'échelle 0-20 de l'app).
        $adresse  = $this->surVingt($bilan, [fn(BilanCompetence $b) => $b->getQttAdresse(), fn(BilanCompetence $b) => $b->getQttEfficacitePanier()]);
        $dribble  = $this->surVingt($bilan, [fn(BilanCompetence $b) => $b->getQttAisance()]);
        $defense  = $this->surVingt($bilan, [fn(BilanCompetence $b) => $b->getQttDefense(), fn(BilanCompetence $b) => $b->getQttRebondCatcher(), fn(BilanCompetence $b) => $b->getQttRebondTransiter()]);
        $physique = $this->surVingt($bilan, [fn(BilanCompetence $b) => $b->getQpEnchainement(), fn(BilanCompetence $b) => $b->getQpVitesse(), fn(BilanCompetence $b) => $b->getQpSoinsDuCorps()]);

        // Bonus playground : +1 / 150 réussis cumulés, plafond +2.
        $bonusTir     = min(2, intdiv($this->playgroundRepo->totalReussis($moi, 'tir'), 150));
        $bonusDribble = min(2, intdiv($this->playgroundRepo->totalReussis($moi, 'dribble'), 150));

        return new JsonResponse([
            'valeurs' => [
                'adresse'  => min(20, $adresse + $bonusTir),
                'dribble'  => min(20, $dribble + $bonusDribble),
                'defense'  => $defense,
                'physique' => $physique,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PATCH /api/pirb/profil/bio
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/profil/bio', name: 'api_pirb_profil_bio', methods: ['PATCH'])]
    public function bio(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('bio', $data)) {
            return new JsonResponse(['error' => 'Champ bio attendu.'], Response::HTTP_BAD_REQUEST);
        }

        $bio = $data['bio'];
        if ($bio !== null && !is_string($bio)) {
            return new JsonResponse(['error' => 'Bio invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (is_string($bio) && mb_strlen($bio) > self::BIO_MAX) {
            return new JsonResponse(
                ['error' => 'Bio trop longue (' . self::BIO_MAX . ' caractères max).'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // setBio trim déjà ; une chaîne vide devient null (pas de bio ≠ bio vide).
        $moi->setBio(is_string($bio) && trim($bio) !== '' ? $bio : null);
        $this->em->flush();

        return new JsonResponse(['bio' => $moi->getBio()]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET + PUT /api/pirb/confidentialite
    // ─────────────────────────────────────────────────────────────────────

    #[Route('/api/pirb/confidentialite', name: 'api_pirb_confidentialite_get', methods: ['GET'])]
    public function confidentialiteGet(): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        return new JsonResponse($this->confidentialiteComplete($moi));
    }

    #[Route('/api/pirb/confidentialite', name: 'api_pirb_confidentialite_put', methods: ['PUT'])]
    public function confidentialitePut(Request $request): JsonResponse
    {
        $moi = $this->joueurOu404();
        if ($moi instanceof JsonResponse) { return $moi; }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Corps JSON attendu.'], Response::HTTP_BAD_REQUEST);
        }

        // On ne lit QUE les clés connues, re-typées bool strictement.
        $reglages = [];
        foreach (self::CLES_CONFIDENTIALITE as $cle) {
            $reglages[$cle] = isset($data[$cle]) && $data[$cle] === true;
        }
        $moi->setConfidentialite($reglages);
        $this->em->flush();

        return new JsonResponse($this->confidentialiteComplete($moi));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Privé
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Moyenne d'un groupe de notes bilan (sur 10) → échelle 0-20.
     * Notes absentes ignorées ; aucun chiffre du tout → 10 (le neutre).
     *
     * @param array<callable(BilanCompetence): ?int> $lecteurs
     */
    private function surVingt(?BilanCompetence $bilan, array $lecteurs): int
    {
        if ($bilan === null) { return 10; }
        $notes = [];
        foreach ($lecteurs as $lire) {
            $n = $lire($bilan);
            if ($n !== null) { $notes[] = $n; }
        }
        if ($notes === []) { return 10; }
        return (int) max(0, min(20, round(array_sum($notes) / count($notes) * 2)));
    }

    /** Réglages stockés complétés par le défaut TOUT PRIVÉ (clé absente = false). */
    private function confidentialiteComplete(Joueur $joueur): array
    {
        $stocke = $joueur->getConfidentialite() ?? [];
        $complet = [];
        foreach (self::CLES_CONFIDENTIALITE as $cle) {
            $complet[$cle] = ($stocke[$cle] ?? false) === true;
        }
        return $complet;
    }

    /** 4e copie de joueurOu404 — le trait est maintenant DÛ (noté dette, à faire au prochain refactor serveur). */
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
