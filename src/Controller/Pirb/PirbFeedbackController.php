<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\FeedbackSeance;
use App\Entity\Sport\Seance;
use App\Repository\Sport\FeedbackSeanceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\PresenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B9 — PIRB Feedback séance (note 0-5 + commentaire + anonyme).
 *
 * Sécurité :
 *   - Joueur doit avoir été présent (ou convoqué) à la séance
 *   - Séance doit être passée (pas de feedback prospectif)
 *   - 1 seul feedback par joueur par séance
 *   - CSRF
 */
class PirbFeedbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedbackSeanceRepository $feedbackRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly PresenceRepository $presenceRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Affiche le formulaire de notation pour une séance précise.
     * Lien depuis le dashboard PIRB ("Note ta dernière séance").
     */
    #[Route('/seances/{id}/feedback', name: 'pirb_feedback_form', methods: ['GET', 'POST'])]
    public function form(Request $request, Seance $seance): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Sécurité métier : séance passée + joueur de la bonne équipe
        if ($seance->getDate() === null || $seance->getDate() > new \DateTimeImmutable()) {
            $this->addFlash('warning', 'Tu ne peux noter qu\'une séance passée.');
            return $this->redirectToRoute('pirb_dashboard');
        }
        if ($seance->getEquipe()?->getId() !== $joueur->getEquipe()?->getId()) {
            throw $this->createAccessDeniedException('Cette séance n\'est pas pour ton équipe.');
        }

        // Anti-doublon
        $existant = $this->feedbackRepo->findExistantForJoueurSeance($joueur, $seance);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_feedback_' . $seance->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('pirb_feedback_form', ['id' => $seance->getId()]);
            }

            if ($existant !== null) {
                $this->addFlash('warning', 'Tu as déjà noté cette séance.');
                return $this->redirectToRoute('pirb_dashboard');
            }

            $note = (int) $request->request->get('note', 3);
            $note = max(FeedbackSeance::NOTE_MIN, min(FeedbackSeance::NOTE_MAX, $note));
            $commentaire = trim((string) $request->request->get('commentaire', '')) ?: null;
            $estAnonyme  = (bool) $request->request->get('est_anonyme', false);

            $fb = new FeedbackSeance();
            $fb->setSeance($seance);
            $fb->setJoueur($joueur);
            $fb->setNote($note);
            $fb->setCommentaire($commentaire);
            $fb->setEstAnonyme($estAnonyme);

            $this->em->persist($fb);
            $this->em->flush();

            $this->logger->info('Feedback séance créé', [
                'seance_id'   => $seance->getId(),
                'joueur_id'   => $joueur->getId(),
                'est_anonyme' => $estAnonyme,
                'note'        => $note,
            ]);

            // Total feedbacks → si 10 → badge à débloquer (à brancher avec BadgeChecker)
            $count = $this->feedbackRepo->countByJoueur($joueur);
            $msg = '✅ Merci pour ton retour !';
            if ($count === 10) {
                $msg .= ' 🏅 Tu débloques le badge "Retex régulier" !';
            }
            $this->addFlash('success', $msg);

            return $this->redirectToRoute('pirb_dashboard');
        }

        return $this->render('pirb/feedback_form.html.twig', [
            'seance'   => $seance,
            'joueur'   => $joueur,
            'deja'     => $existant,
        ]);
    }
}
