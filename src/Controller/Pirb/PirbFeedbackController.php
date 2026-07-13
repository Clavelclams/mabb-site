<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\FeedbackParticipation;
use App\Entity\Sport\FeedbackSeance;
use App\Entity\Sport\Seance;
use App\Repository\Sport\FeedbackParticipationRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le retour d'une joueuse sur une séance passée.
 *
 * Deux écritures, jamais reliées :
 *
 *   feedback_participation  ->  cette joueuse a répondu.  (identité, sans contenu)
 *   feedback_seance         ->  voici ce qui a été dit.   (contenu, sans identité si anonyme)
 *
 * Si la joueuse coche "anonyme", joueur_id n'est pas mis à NULL après coup : il
 * n'est jamais écrit. Rien à effacer, rien à retrouver.
 *
 * Interdits, à ne jamais réintroduire :
 *   - écrire setJoueur() sur un retour anonyme ;
 *   - logger l'identité de la joueuse à côté de sa note ou de son commentaire ;
 *   - chercher un doublon en interrogeant feedback_seance par joueur.
 */
class PirbFeedbackController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedbackParticipationRepository $participationRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/seances/{id}/feedback', name: 'pirb_feedback_form', methods: ['GET', 'POST'])]
    public function form(Request $request, Seance $seance): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée à ton compte.');

            return $this->redirectToRoute('pirb_dashboard');
        }

        if ($seance->getDate() === null || $seance->getDate() > new \DateTimeImmutable()) {
            $this->addFlash('warning', 'Tu ne peux donner ton avis que sur une séance déjà passée.');

            return $this->redirectToRoute('pirb_dashboard');
        }

        if ($seance->getEquipe()?->getId() !== $joueur->getEquipe()?->getId()) {
            throw $this->createAccessDeniedException('Cette séance n\'est pas celle de ton équipe.');
        }

        // Anti-doublon. On interroge la table des participations, pas celle des
        // retours : celle des retours ne sait plus qui a écrit quoi, c'est le but.
        $dejaRepondu = $this->participationRepo->aDejaRepondu($joueur, $seance);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_feedback_' . $seance->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Formulaire expiré, recommence.');

                return $this->redirectToRoute('pirb_feedback_form', ['id' => $seance->getId()]);
            }

            if ($dejaRepondu) {
                $this->addFlash('warning', 'Tu as déjà donné ton avis sur cette séance.');

                return $this->redirectToRoute('pirb_dashboard');
            }

            $note = (int) $request->request->get('note', 3);
            $note = max(FeedbackSeance::NOTE_MIN, min(FeedbackSeance::NOTE_MAX, $note));

            $commentaire = trim((string) $request->request->get('commentaire', '')) ?: null;

            // L'anonymat est le défaut, pas une option cachée. Elle doit cocher
            // activement pour signer. Une joueuse de 14 ans qui donne son avis sur son
            // coach ne devrait pas avoir à chercher la case qui la protège.
            $estAnonyme = !$request->request->getBoolean('signer', false);

            // 1. Le contenu. Sans identité si elle a demandé l'anonymat.
            $retour = new FeedbackSeance();
            $retour->setSeance($seance);
            $retour->setNote($note);
            $retour->setCommentaire($commentaire);
            $retour->setEstAnonyme($estAnonyme);

            if (!$estAnonyme) {
                $retour->setJoueur($joueur);
            }

            // 2. L'identité. Sans contenu.
            $participation = new FeedbackParticipation();
            $participation->setJoueur($joueur);
            $participation->setSeance($seance);

            $this->em->persist($retour);
            $this->em->persist($participation);
            $this->em->flush();

            // Le log ne dit jamais qui a écrit quoi. Sur un retour anonyme, il ne
            // nomme personne : ce serait exactement la fuite qu'on vient de fermer.
            $this->logger->info('Retour de séance enregistré', [
                'seance_id'   => $seance->getId(),
                'est_anonyme' => $estAnonyme,
                'joueur_id'   => $estAnonyme ? null : $joueur->getId(),
            ]);

            $total = $this->participationRepo->countByJoueur($joueur);

            $message = 'Merci, ton avis est parti.';
            if ($total === 10) {
                $message .= ' Et tu débloques le badge "Retex régulier".';
            }
            $this->addFlash('success', $message);

            return $this->redirectToRoute('pirb_dashboard');
        }

        return $this->render('pirb/feedback_form.html.twig', [
            'seance'       => $seance,
            'joueur'       => $joueur,
            'deja_repondu' => $dejaRepondu,
            'seuil'        => \App\Repository\Sport\FeedbackSeanceRepository::SEUIL_AFFICHAGE,
        ]);
    }
}
