<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Seance;
use App\Entity\Sport\SeanceSolo;
use App\Repository\Sport\FeedbackParticipationRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\SeanceRepository;
use App\Repository\Sport\SeanceSoloRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les séances d'entraînement, vues par la joueuse.
 *
 * Elle peut voir les séances à venir et passées, donner son avis sur une séance
 * passée, et déclarer un entraînement fait toute seule.
 *
 * Sur l'avis : il n'y a plus qu'un seul chemin, PirbFeedbackController. Il y en
 * avait deux (une entité NoteSeance et une entité FeedbackSeance faisaient la même
 * chose), et celui-ci promettait l'anonymat à l'écran tout en enregistrant
 * l'identité de la joueuse. Il a été supprimé.
 *
 * Ici, on sait seulement SI une joueuse a déjà répondu, via feedback_participation.
 * On ne sait pas ce qu'elle a écrit, et c'est très bien ainsi.
 */
#[Route('/seances', name: 'pirb_seances_')]
class PirbSeancesController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly SeanceRepository $seanceRepo,
        private readonly FeedbackParticipationRepository $participationRepo,
        private readonly SeanceSoloRepository $soloRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Liste des séances — vue principale.
     *
     * Affiche :
     *   - Prochaines séances (à venir) — titre + date + lieu
     *     Si contenuPrive = true → pas de description, badge "🔒"
     *   - Séances passées (historique 20 dernières)
     *     Avec badge "✓ Noté" / bouton "Noter" selon si elle a noté
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $joueur = $this->getJoueur();
        if (!$joueur || !$joueur->getEquipe()) {
            $this->addFlash('warning', 'Aucune équipe associée à ta fiche joueuse.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $equipe     = $joueur->getEquipe();
        $prochaines = $this->seanceRepo->findProchaines($equipe, 8);
        $passees    = $this->seanceRepo->findPassees($equipe, 20);

        // Les séances sur lesquelles elle s'est déjà exprimée. On sait qu'elle l'a
        // fait, pas ce qu'elle a dit : le contenu vit dans une autre table, sans lien.
        $dejaRepondu = $this->participationRepo->seancesDejaNoteesParJoueur($joueur);

        // Séances solo déclarées récemment
        $solos = $this->soloRepo->findParJoueur($joueur, 10);

        return $this->render('pirb/seances/index.html.twig', [
            'joueur'      => $joueur,
            'equipe'      => $equipe,
            'prochaines'  => $prochaines,
            'passees'     => $passees,
            'dejaRepondu' => $dejaRepondu,
            'solos'       => $solos,
        ]);
    }

    /**
     * Détail d'une séance + formulaire de notation.
     *
     * Contenu visible :
     *   - Si contenuPrive=false → titre, description, thèmes, fichiers
     *   - Si contenuPrive=true  → seulement titre + lieu (le reste est masqué)
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Seance $seance): Response
    {
        $joueur = $this->getJoueur();
        if (!$joueur) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Vérification : la joueuse est bien dans l'équipe de la séance
        if ($joueur->getEquipe()?->getId() !== $seance->getEquipe()?->getId()) {
            throw $this->createAccessDeniedException('Cette séance ne concerne pas ton équipe.');
        }

        $estPassee   = $seance->getDate() < new \DateTimeImmutable('today');
        $dejaRepondu = $this->participationRepo->aDejaRepondu($joueur, $seance);

        return $this->render('pirb/seances/show.html.twig', [
            'joueur'      => $joueur,
            'seance'      => $seance,
            'estPassee'   => $estPassee,
            'dejaRepondu' => $dejaRepondu,
            // On ne donne son avis que sur une séance passée, et une seule fois.
            'peutRepondre' => $estPassee && !$dejaRepondu,
        ]);
    }

    /**
     * Formulaire de déclaration d'une séance solo.
     *
     * GET  /seances/solo/declarer
     * POST /seances/solo/declarer
     */
    #[Route('/solo/declarer', name: 'solo_declarer', methods: ['GET', 'POST'])]
    public function declarerSolo(Request $request): Response
    {
        $joueur = $this->getJoueur();
        if (!$joueur) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('declarer_solo', $token)) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('pirb_seances_solo_declarer');
            }

            // Validation basique des champs
            $dateStr   = (string) $request->request->get('date_solo', '');
            $type      = (string) $request->request->get('type', 'Autre');
            $duree     = (int) $request->request->get('duree_minutes', 60);
            $desc      = trim((string) $request->request->get('description', ''));

            if (!$dateStr || !\DateTimeImmutable::createFromFormat('Y-m-d', $dateStr)) {
                $this->addFlash('error', 'Date invalide.');
                return $this->redirectToRoute('pirb_seances_solo_declarer');
            }

            $dateSolo = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
            if ($dateSolo > new \DateTimeImmutable('today')) {
                $this->addFlash('error', 'Tu ne peux déclarer qu\'une séance déjà réalisée (date passée ou aujourd\'hui).');
                return $this->redirectToRoute('pirb_seances_solo_declarer');
            }

            if (!array_key_exists($type, SeanceSolo::TYPES)) {
                $type = 'Autre';
            }

            $duree = max(10, min(300, $duree)); // clamp 10-300 min

            $solo = new SeanceSolo();
            $solo->setJoueur($joueur);
            $solo->setDateSolo($dateSolo);
            $solo->setType($type);
            $solo->setDureeMinutes($duree);
            $solo->setDescription($desc ?: null);

            $this->em->persist($solo);
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Séance "%s" du %s déclarée ! En attente de validation par ton coach.',
                SeanceSolo::TYPES[$type],
                $dateSolo->format('d/m/Y')
            ));

            return $this->redirectToRoute('pirb_seances_index');
        }

        return $this->render('pirb/seances/solo_declarer.html.twig', [
            'joueur'   => $joueur,
            'types'    => SeanceSolo::TYPES,
            'solos'    => $this->soloRepo->findParJoueur($joueur, 5), // dernières déclarations
        ]);
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    private function getJoueur(): ?\App\Entity\Sport\Joueur
    {
        /** @var User $user */
        $user   = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if (!$joueur) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée à ton compte.');
        }

        return $joueur;
    }
}
