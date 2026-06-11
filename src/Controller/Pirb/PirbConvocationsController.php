<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\Convocation;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B14 — PIRB : réponse aux convocations rencontres.
 *
 * La joueuse voit toutes ses convocations à venir + passées,
 * peut répondre Présent/Absent/Incertain + ajouter un motif.
 *
 * Sécurité :
 *   - Vérif que la convocation est bien pour le joueur lié au user connecté
 *   - CSRF sur POST
 *   - Pas de modif après la date de la rencontre (verrou métier)
 */
class PirbConvocationsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConvocationRepository $convocationRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Liste des convocations du joueur connecté (à venir en haut, passées en bas).
     */
    #[Route('/convocations', name: 'pirb_convocations', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée à ton compte. Contacte le staff.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $now = new \DateTimeImmutable();

        // À venir = rencontres futures
        $aVenir = $this->convocationRepo->createQueryBuilder('c')
            ->join('c.rencontre', 'r')
            ->addSelect('r')
            ->where('c.joueur = :j')
            ->andWhere('r.date >= :now')
            ->setParameter('j', $joueur)
            ->setParameter('now', $now)
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();

        // Passées = derniers 20
        $passees = $this->convocationRepo->createQueryBuilder('c')
            ->join('c.rencontre', 'r')
            ->addSelect('r')
            ->where('c.joueur = :j')
            ->andWhere('r.date < :now')
            ->setParameter('j', $joueur)
            ->setParameter('now', $now)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->render('pirb/convocations.html.twig', [
            'joueur'   => $joueur,
            'a_venir'  => $aVenir,
            'passees'  => $passees,
        ]);
    }

    /**
     * POST réponse à une convocation.
     */
    #[Route('/convocations/{id}/repondre', name: 'pirb_convocations_repondre', methods: ['POST'])]
    public function repondre(Request $request, Convocation $convocation): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Sécurité IDOR : le joueur connecté doit être celui de la convocation
        $joueurConnecte = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueurConnecte === null || $convocation->getJoueur()?->getId() !== $joueurConnecte->getId()) {
            $this->logger->warning('Tentative de réponse convocation IDOR', [
                'user_id'        => $user->getId(),
                'convocation_id' => $convocation->getId(),
                'ip'             => $request->getClientIp(),
            ]);
            throw $this->createAccessDeniedException('Cette convocation ne te concerne pas.');
        }

        // CSRF
        if (!$this->isCsrfTokenValid('pirb_conv_' . $convocation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('pirb_convocations');
        }

        // Verrou métier : pas de réponse après la date du match
        $rencontre = $convocation->getRencontre();
        if ($rencontre !== null && $rencontre->getDate() !== null && $rencontre->getDate() < new \DateTimeImmutable()) {
            $this->addFlash('warning', 'Cette rencontre est déjà passée, tu ne peux plus répondre.');
            return $this->redirectToRoute('pirb_convocations');
        }

        $reponse = (string) $request->request->get('reponse', '');
        if (!in_array($reponse, Convocation::REPONSES, true)) {
            $this->addFlash('error', 'Réponse invalide.');
            return $this->redirectToRoute('pirb_convocations');
        }

        $motif = trim((string) $request->request->get('motif', ''));

        $convocation->setReponse($reponse);
        $convocation->setMotif($motif !== '' ? $motif : null);
        $this->em->flush();

        $this->logger->info('Réponse convocation enregistrée', [
            'convocation_id' => $convocation->getId(),
            'joueur_id'      => $joueurConnecte->getId(),
            'reponse'        => $reponse,
        ]);

        $msg = match ($reponse) {
            Convocation::REPONSE_PRESENT   => '✅ Présent·e — Top, le coach est prévenu.',
            Convocation::REPONSE_ABSENT    => '❌ Absent·e — Prévenu, à la prochaine.',
            Convocation::REPONSE_INCERTAIN => '🤔 Incertain·e — Confirme dès que tu sais.',
        };
        $this->addFlash('success', $msg);

        return $this->redirectToRoute('pirb_convocations');
    }
}
