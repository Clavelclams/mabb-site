<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Core\User;
use App\Entity\Sport\BilanCompetence;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Service\SaisonService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbApiBilanController — [Bloc G, 13/07/2026]
 *
 * Le bilan de compétences de la joueuse, en natif.
 *
 *   GET /api/pirb/bilan[?saison=YYYY-YYYY] → { saisons[], saisonSelectionnee, bilan|null }
 *
 * ══════════════════════════════════════════════════════════════════════════
 * ⚠️  RGPD — POURQUOI CE CONTRÔLEUR N'EXPOSE PAS L'ENTITÉ ENTIÈRE
 * ══════════════════════════════════════════════════════════════════════════
 * L'entité BilanCompetence contient, à côté des notes sportives :
 *   numeroLicence, numSecuSociale, mutuelle, problemeSante, allergies,
 *   regimeAlimentaire, taille, poids, envergure, tailleAssise, pointure.
 *
 * Les six premiers sont des DONNÉES DE SANTÉ. Au sens du RGPD (article 9),
 * c'est une « catégorie particulière » : le régime le plus protégé qui existe.
 * Et notre public est MINEUR. Les envoyer dans une API mobile serait une faute,
 * pas une facilité.
 *
 * Ce contrôleur sérialise donc en LISTE BLANCHE : on énumère un par un les
 * champs qu'on autorise, et rien d'autre ne sort. C'est le principe du moindre
 * privilège. L'inverse (« je renvoie l'objet et je retire ce qui gêne ») est le
 * piège classique : le jour où un dev ajoute un champ sensible à l'entité, il
 * part tout seul dans l'API sans que personne ne le décide.
 *
 * La page web PIRB fait déjà exactement ça (son template n'affiche aucun champ
 * de santé). On reste cohérent.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ISOLATION : le bilan est lu depuis la fiche Joueur du user connecté. Aucun
 * paramètre {id} : impossible de demander le bilan d'une autre joueuse.
 *
 * STATUT : on renvoie aussi les brouillons (avec leur statut), comme le web.
 * L'app le signale clairement, pour ne pas faire passer un jet du coach pour
 * une évaluation définitive.
 */
class PirbApiBilanController extends AbstractController
{
    public function __construct(
        private readonly BilanCompetenceRepository $bilanRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly SaisonService $saisonService,
    ) {}

    #[Route('/api/pirb/bilan', name: 'api_pirb_bilan', methods: ['GET'])]
    public function bilan(Request $request): JsonResponse
    {
        $joueur = $this->joueurOu404();
        if ($joueur instanceof JsonResponse) { return $joueur; }

        // Un bilan par saison (le plus récent si le coach en a créé plusieurs).
        $bilanParSaison = [];
        foreach ($this->bilanRepo->findByJoueur($joueur) as $b) {
            $bilanParSaison[$b->getSaison()] ??= $b;
        }

        // Les saisons sélectionnables = celles du calendrier + celles qui ont un
        // bilan. Sans ça, la saison en cours serait invisible tant que le coach
        // n'a rien saisi (le bug corrigé côté web en V2.4g).
        $saisons = array_values(array_unique(array_merge(
            $this->saisonService->getSaisonsDisponibles(),
            array_keys($bilanParSaison),
        )));
        rsort($saisons);

        $demandee = $request->query->get('saison');
        $saisonSelectionnee = (is_string($demandee) && in_array($demandee, $saisons, true))
            ? $demandee
            // getSaisonCourante() et PAS getSaisonActive() : cette dernière lit la
            // SESSION, or le firewall API est `stateless: true` → aucune session →
            // SessionNotFoundException → 500. Piège classique quand on recopie un
            // contrôleur web dans une API.
            : $this->saisonService->getSaisonCourante();

        $bilan = $bilanParSaison[$saisonSelectionnee] ?? null;

        return new JsonResponse([
            'saisons'            => $saisons,
            'saisonSelectionnee' => $saisonSelectionnee,
            // null = pas encore de bilan cette saison. L'app l'assume, elle
            // n'invente rien et ne montre pas des zéros.
            'bilan'              => $bilan !== null ? $this->serialiser($bilan) : null,
        ]);
    }

    /**
     * LISTE BLANCHE. Rien d'autre ne sort d'ici. Voir l'avertissement RGPD
     * en tête de fichier avant d'ajouter la moindre ligne.
     *
     * @return array<string, mixed>
     */
    private function serialiser(BilanCompetence $b): array
    {
        return [
            'saison'             => $b->getSaison(),
            'contexte'           => $b->getContexte(),
            'statut'             => $b->getStatut(), // 'brouillon' | 'valide'
            'dateEvaluation'     => $b->getDateEvaluation()?->format('Y-m-d'),
            'moyenne'            => $b->getMoyenne(),
            'nbCriteresRemplis'  => $b->getNbCriteresRemplis(),
            'profilDeJeu'        => $b->getProfilDeJeu(),
            'coach'              => $b->getCoach()?->getPrenom() ?? null,

            // Les mots du coach : c'est le contenu le plus attendu.
            'pointsForts'        => $b->getPointsForts(),
            'pointsVigilance'    => $b->getPointsVigilance(),
            'axesTravail'        => $b->getAxesTravail(),

            // Les critères notés, groupés comme sur le web. `null` = non évalué,
            // et ça reste null : un critère vide n'est pas un zéro.
            'groupes' => [
                [
                    'code'     => 'vie_de_groupe',
                    'titre'    => 'Vie de groupe',
                    'criteres' => [
                        ['libelle' => 'Respect des règles', 'note' => $b->getVqRespectRegles()],
                        ['libelle' => 'Ponctualité', 'note' => $b->getVqPonctualite()],
                        ['libelle' => 'Discipline', 'note' => $b->getVqDiscipline()],
                        ['libelle' => 'Vie de groupe', 'note' => $b->getVqVieGroupe()],
                        ['libelle' => 'Rangement', 'note' => $b->getVqRangement()],
                    ],
                ],
                [
                    'code'     => 'mental',
                    'titre'    => 'Qualités mentales',
                    'criteres' => [
                        ['libelle' => 'Enthousiasme', 'note' => $b->getQmEnthousiasme()],
                        ['libelle' => 'Détermination', 'note' => $b->getQmDetermination()],
                        ['libelle' => 'Confiance', 'note' => $b->getQmConfiance()],
                        ['libelle' => 'Curiosité', 'note' => $b->getQmCuriosite()],
                        ['libelle' => 'Autonomie', 'note' => $b->getQmAutonomie()],
                        ['libelle' => 'Concentration', 'note' => $b->getQmConcentration()],
                    ],
                ],
                [
                    'code'     => 'technique',
                    'titre'    => 'Technique et tactique',
                    'criteres' => [
                        ['libelle' => 'Adresse', 'note' => $b->getQttAdresse()],
                        ['libelle' => 'Efficacité au panier', 'note' => $b->getQttEfficacitePanier()],
                        ['libelle' => 'Aisance', 'note' => $b->getQttAisance()],
                        ['libelle' => 'Jeu sans ballon', 'note' => $b->getQttJeuSansBallons()],
                        ['libelle' => 'Compréhension', 'note' => $b->getQttComprehension()],
                        ['libelle' => 'Défense', 'note' => $b->getQttDefense()],
                        ['libelle' => 'Rebond (attraper)', 'note' => $b->getQttRebondCatcher()],
                        ['libelle' => 'Rebond (transiter)', 'note' => $b->getQttRebondTransiter()],
                    ],
                ],
                [
                    'code'     => 'physique',
                    'titre'    => 'Physique',
                    'criteres' => [
                        ['libelle' => 'Enchaînement', 'note' => $b->getQpEnchainement()],
                        ['libelle' => 'Vitesse', 'note' => $b->getQpVitesse()],
                        ['libelle' => 'Soin du corps', 'note' => $b->getQpSoinsDuCorps()],
                    ],
                ],
            ],
        ];
    }

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
