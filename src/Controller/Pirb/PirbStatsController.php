<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\DemandeAccesPdf;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ActionMatchRepository;
use App\Repository\Sport\DemandeAccesPdfRepository;
use App\Repository\Sport\EvaluationFfbbRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\SessionStatsLiveRepository;
use App\Repository\Sport\TirFfbbRepository;
use App\Service\Stats\ActionMatchAggregator;
use App\Service\Stats\JoueurStatsAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * B10/B11 — PIRB Stats personnelles.
 *
 * Routes :
 *   GET /stats                  → résumé saison
 *   GET /stats/match/{id}       → détail d'un match
 *
 * Source : EvaluationMatch (saisi par coach).
 * Phase 2 : fusion avec Stats Live promues officielles (B11.2).
 */
class PirbStatsController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly JoueurStatsAggregator $aggregator,
        private readonly EvaluationFfbbRepository $evalFfbbRepo,
        private readonly TirFfbbRepository $tirFfbbRepo,
        private readonly SessionStatsLiveRepository $sessionRepo,
        private readonly ActionMatchAggregator $actionAggregator,
        private readonly ActionMatchRepository $actionMatchRepo,
        private readonly DemandeAccesPdfRepository $demandeAccesPdfRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/stats', name: 'pirb_stats', methods: ['GET'])]
    public function index(
        \App\Repository\Sport\RencontreRepository $rencontreRepo,
        \App\Service\SaisonService $saisonService,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // [V2.4d 06/07/2026] La page respecte ENFIN le sélecteur de saison
        // du header PIRB (avant : le dropdown changeait la session mais la
        // page affichait toujours tout, donc "toujours 2025-2026").
        $saison = $saisonService->getSaisonActive();

        $stats = $this->aggregator->statsSaison($joueur, $saison);

        // [15/06/2026] Liste des matchs de l'équipe pour accéder aux PDFs FFBB.
        // [V2.4d] Équipe résolue PAR SAISON (affectations) + matchs bornés
        // aux dates de la saison sélectionnée. Saison passée sans équipe ni
        // matchs → le template affiche "pas de données".
        $matchsEquipe = [];
        $equipeSaison = $joueur->equipePourSaison($saison) ?? $joueur->getEquipe();
        if ($equipeSaison !== null && preg_match('/^(\d{4})-(\d{4})$/', $saison, $m)) {
            $matchsEquipe = $rencontreRepo->createQueryBuilder('r')
                ->where('r.equipe = :eq')
                ->andWhere('r.date < :now')
                ->andWhere('r.date >= :debut')
                ->andWhere('r.date < :fin')
                ->setParameter('eq', $equipeSaison)
                ->setParameter('now', new \DateTimeImmutable())
                ->setParameter('debut', new \DateTimeImmutable($m[1] . '-07-01'))
                ->setParameter('fin',   new \DateTimeImmutable($m[2] . '-07-01'))
                ->orderBy('r.date', 'DESC')
                ->setMaxResults(20)
                ->getQuery()
                ->getResult();
        }

        return $this->render('pirb/stats.html.twig', [
            'joueur'        => $joueur,
            'stats'         => $stats,
            'matchs_equipe' => $matchsEquipe,
            'saison'        => $saison,
        ]);
    }

    #[Route('/stats/match/{id}', name: 'pirb_stats_match', methods: ['GET'])]
    public function match(Rencontre $rencontre): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        // [B22a 12/06/2026] Filtre RGPD : la joueuse ne voit cette page QUE si
        // elle appartient à l'équipe du match (transparence intra-équipe).
        // [07/07/2026] L'équipe de référence dépend de la SAISON du match, pas
        // de l'équipe actuelle : après la bascule de saison (1er juillet), son
        // équipe courante change, mais elle doit garder l'accès à SES matchs des
        // saisons passées. On compare donc à son équipe DE LA SAISON du match
        // (avec fallback sur l'équipe courante).
        $equipeMatchId = $rencontre->getEquipe()?->getId();
        $saisonMatch   = $rencontre->getSaison();
        $monEquipeCetteSaison = $saisonMatch !== null
            ? $joueur->equipePourSaison($saisonMatch)
            : $joueur->getEquipe();
        $appartientEquipe = $equipeMatchId !== null && (
            $monEquipeCetteSaison?->getId() === $equipeMatchId
            || $joueur->getEquipe()?->getId() === $equipeMatchId
        );
        if (!$appartientEquipe) {
            throw $this->createAccessDeniedException('Ce match ne concerne pas ton équipe.');
        }

        $eval = $this->aggregator->evalForMatch($joueur, $rencontre->getId());

        // [B22b 12/06/2026] Stats FFBB extraites du PDF resume :
        //   - statsFfbbMoi : ma propre ligne (peut être null si pas dans le match)
        //   - statsFfbbEquipe : toutes les lignes de mon équipe (pour vue ensemble)
        // Affiché dans un toggle "Stats coach (saisie manuelle)" / "Stats FFBB (officielles)"
        $statsFfbbMoi = $this->evalFfbbRepo->findForJoueurEtRencontre($joueur, $rencontre->getId());
        $statsFfbbEquipe = $this->evalFfbbRepo->findForRencontre($rencontre);

        // [B22c 12/06/2026] Tirs FFBB de la joueuse pour ce match (juste compteur V1)
        $tirsFfbbMatch = $this->tirFfbbRepo->createQueryBuilder('t')
            ->where('t.joueur = :j')
            ->andWhere('t.rencontre = :r')
            ->andWhere('t.source = :s')
            ->setParameter('j', $joueur)
            ->setParameter('r', $rencontre)
            ->setParameter('s', \App\Entity\Sport\TirFfbb::SOURCE_FFBB)
            ->getQuery()
            ->getResult();

        // [B22d 12/06/2026] 3ème source : stats agrégées depuis SessionStatsLive OFFICIELLE
        // ActionMatchAggregator::agreger() retourne array de stats individuelles
        // calculées depuis les ActionMatch de la session officielle.
        $sessionOfficielle = $this->sessionRepo->findOfficielleByRencontre($rencontre);
        $statsLiveMoi = null;
        $statsLiveEquipe = [];
        if ($sessionOfficielle !== null) {
            $statsLiveMoi = $this->actionAggregator->agreger($joueur, $rencontre);

            // Pour chaque joueuse de l'équipe : agréger ses stats
            $coequipieres = $rencontre->getEquipe()?->getJoueurs() ?? [];
            foreach ($coequipieres as $j) {
                if (!$j->isActive()) continue;
                $agg = $this->actionAggregator->agreger($j, $rencontre);
                // On ne garde que si la joueuse a effectivement des actions
                if (!empty($agg) && ($agg['nb_actions'] ?? 0) > 0) {
                    $statsLiveEquipe[] = ['joueur' => $j, 'stats' => $agg];
                }
            }
        }

        // [B22a-sec] Statuts des demandes d'accès PDF pour ce match.
        // Le template affiche des boutons différents selon le statut :
        //   null → bouton "Demander l'accès"
        //   pending → badge "En attente"
        //   approved → lien téléchargement direct
        //   rejected → bouton "Re-demander"
        $demandesAccesPdf = $this->demandeAccesPdfRepo->findDemandesParMatch($joueur, $rencontre);

        return $this->render('pirb/stats_match.html.twig', [
            'joueur'               => $joueur,
            'rencontre'            => $rencontre,
            'eval'                 => $eval,
            'stats_ffbb_moi'       => $statsFfbbMoi,
            'stats_ffbb_equipe'    => $statsFfbbEquipe,
            'tirs_ffbb_match'      => $tirsFfbbMatch,
            'session_officielle'   => $sessionOfficielle,
            'stats_live_moi'       => $statsLiveMoi,
            'stats_live_equipe'    => $statsLiveEquipe,
            'demandes_acces_pdf'   => $demandesAccesPdf,
        ]);
    }

    /**
     * [B22c 12/06/2026] Shot chart cumulé saison de la joueuse.
     * Affiche le terrain SVG avec tous les tirs FFBB (+ Stats Live future) marqués
     * sur l'ensemble des matchs joués. Permet d'identifier les zones de tir efficaces.
     */
    #[Route('/stats/shotchart', name: 'pirb_stats_shotchart', methods: ['GET'])]
    public function shotchart(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            $this->addFlash('warning', 'Aucune fiche joueuse associée.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Tous les tirs réussis FFBB de la joueuse (saison toutes confondues)
        $tirsFfbb = $this->tirFfbbRepo->findForJoueur($joueur);

        // [B22d 12/06/2026] Tirs depuis ActionMatch (Stats Live officielles)
        // → on cherche les actions TYPE_TIR_*_REUSSI avec position X/Y
        $typesReussis = [
            \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_INT_REUSSI,
            \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_EXT_REUSSI,
            \App\Entity\Sport\ActionMatch::TYPE_TIR_3PT_REUSSI,
        ];
        $tirsLive = $this->actionMatchRepo->createQueryBuilder('a')
            ->leftJoin('a.session', 's')->addSelect('s')
            ->leftJoin('s.rencontre', 'r')->addSelect('r')
            ->where('a.joueur = :j')
            ->andWhere('a.type IN (:types)')
            ->andWhere('s.statut = :off')
            ->setParameter('j', $joueur)
            ->setParameter('types', $typesReussis)
            ->setParameter('off', \App\Entity\Sport\SessionStatsLive::STATUT_OFFICIELLE)
            ->getQuery()
            ->getResult();

        // Décompte par type pour stats globales (FFBB + Live confondus)
        $countByType = ['2pt_int' => 0, '2pt_ext' => 0, '3pt' => 0, 'inconnu' => 0];
        $countWithPos = 0;
        foreach ($tirsFfbb as $t) {
            $type = $t->getTypeTir() ?? 'inconnu';
            $countByType[$type] = ($countByType[$type] ?? 0) + 1;
            if ($t->getPositionX() !== null && $t->getPositionY() !== null) {
                $countWithPos++;
            }
        }
        foreach ($tirsLive as $t) {
            $type = match ($t->getType()) {
                \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_INT_REUSSI => '2pt_int',
                \App\Entity\Sport\ActionMatch::TYPE_TIR_2PT_EXT_REUSSI => '2pt_ext',
                \App\Entity\Sport\ActionMatch::TYPE_TIR_3PT_REUSSI     => '3pt',
                default => 'inconnu',
            };
            $countByType[$type] = ($countByType[$type] ?? 0) + 1;
            if ($t->getPositionX() !== null && $t->getPositionY() !== null) {
                $countWithPos++;
            }
        }

        return $this->render('pirb/shotchart.html.twig', [
            'joueur'         => $joueur,
            'tirs'           => $tirsFfbb,         // FFBB (V1 sans coords)
            'tirs_live'      => $tirsLive,         // Stats Live (avec coords X/Y % 0-100)
            'count_by_type'  => $countByType,
            'count_with_pos' => $countWithPos,
            'total'          => count($tirsFfbb) + count($tirsLive),
        ]);
    }

    /**
     * [B22a 12/06/2026] Téléchargement sécurisé d'un PDF FFBB par la joueuse.
     * [B22a-sec 25/06/2026] Ajout du workflow d'approbation coach.
     *
     * Routes :
     *   GET /stats/match/{id}/pdf/feuille
     *   GET /stats/match/{id}/pdf/resume
     *   GET /stats/match/{id}/pdf/positions
     *
     * Sécurité :
     *   - La joueuse ne peut pas télécharger directement.
     *   - Elle doit d'abord faire une demande (POST pirb_stats_match_pdf_request).
     *   - Le coach approuve dans Manager.
     *   - Une fois approved, le GET sert le fichier normalement.
     */
    #[Route('/stats/match/{id}/pdf/{type}', name: 'pirb_stats_match_pdf', methods: ['GET'], requirements: ['type' => 'feuille|resume|positions'])]
    public function downloadPdf(
        Rencontre $rencontre,
        string $type,
        \App\Service\RencontrePdfUploader $pdfUploader,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        // Vérif RGPD : même équipe que la rencontre, POUR LA SAISON du match
        // (accès conservé aux matchs passés après la bascule de saison).
        $equipeMatchId = $rencontre->getEquipe()?->getId();
        $saisonMatch   = $rencontre->getSaison();
        $monEquipeCetteSaison = $saisonMatch !== null
            ? $joueur->equipePourSaison($saisonMatch)
            : $joueur->getEquipe();
        $accede = $equipeMatchId !== null && (
            $monEquipeCetteSaison?->getId() === $equipeMatchId
            || $joueur->getEquipe()?->getId() === $equipeMatchId
        );
        if (!$accede) {
            throw $this->createAccessDeniedException();
        }

        // [B22a-sec] Vérifier l'approbation avant de servir le fichier.
        $demande = $this->demandeAccesPdfRepo->findOneDemande($joueur, $rencontre, $type);

        if ($demande === null || $demande->isPending()) {
            // Pas de demande ou demande en attente → bloquer + message
            if ($demande === null) {
                $this->addFlash('info', 'Tu dois d\'abord demander l\'accès à ce document. Ton coach devra valider.');
            } else {
                $this->addFlash('warning', 'Ta demande est en attente de validation par ton coach.');
            }
            return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
        }

        if ($demande->isRejected()) {
            $msg = 'Ton coach a refusé l\'accès à ce document.';
            if ($demande->getMessageCoach()) {
                $msg .= ' Message : « ' . $demande->getMessageCoach() . ' »';
            }
            $this->addFlash('danger', $msg);
            return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
        }

        // Demande approved → servir le fichier

        // [15/06/2026] Utilise RencontrePdfUploader::getAbsolutePath qui gère
        // les 2 conventions de stockage (filename simple vs path complet).
        $absolutePath = $pdfUploader->getAbsolutePath($rencontre, $type);
        if ($absolutePath === null) {
            throw $this->createNotFoundException('PDF non disponible pour ce match.');
        }

        $labels = ['feuille' => 'feuille-match', 'resume' => 'resume-stats', 'positions' => 'positions-tirs'];
        $filename = sprintf(
            'mabb-%s-vs-%s-%s.pdf',
            $rencontre->getDate()?->format('Y-m-d') ?? 'date',
            preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($rencontre->getAdversaire() ?? 'adv')),
            $labels[$type]
        );

        return $this->file($absolutePath, $filename);
    }

    /**
     * [B22a-sec 25/06/2026] Créer une demande d'accès à un PDF FFBB.
     *
     * Route :
     *   POST /stats/match/{id}/pdf/{type}/demander
     *
     * Logique :
     *   - Si pas de demande → créer (statut=pending)
     *   - Si rejected → remettre en pending (re-demander)
     *   - Si pending → message "déjà en attente"
     *   - Si approved → rediriger vers le download directement
     */
    #[Route('/stats/match/{id}/pdf/{type}/demander', name: 'pirb_stats_match_pdf_request', methods: ['POST'], requirements: ['type' => 'feuille|resume|positions'])]
    public function requestPdfAccess(
        Rencontre $rencontre,
        string $type,
        Request $request,
    ): Response {
        // Protection CSRF
        if (!$this->isCsrfTokenValid('pdf_request_' . $rencontre->getId() . '_' . $type, $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide. Réessaie.');
            return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);

        if ($joueur === null) {
            throw $this->createAccessDeniedException();
        }

        // Vérif RGPD : même équipe, POUR LA SAISON du match (matchs passés OK).
        $equipeMatchId = $rencontre->getEquipe()?->getId();
        $saisonMatch   = $rencontre->getSaison();
        $monEquipeCetteSaison = $saisonMatch !== null
            ? $joueur->equipePourSaison($saisonMatch)
            : $joueur->getEquipe();
        $accede = $equipeMatchId !== null && (
            $monEquipeCetteSaison?->getId() === $equipeMatchId
            || $joueur->getEquipe()?->getId() === $equipeMatchId
        );
        if (!$accede) {
            throw $this->createAccessDeniedException();
        }

        $demande = $this->demandeAccesPdfRepo->findOneDemande($joueur, $rencontre, $type);

        if ($demande !== null && $demande->isApproved()) {
            // Déjà approuvé → rediriger vers le téléchargement
            return $this->redirectToRoute('pirb_stats_match_pdf', [
                'id'   => $rencontre->getId(),
                'type' => $type,
            ]);
        }

        if ($demande !== null && $demande->isPending()) {
            $this->addFlash('info', 'Ta demande est déjà en attente. Ton coach doit la valider.');
            return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
        }

        if ($demande !== null && $demande->isRejected()) {
            // Re-demander après refus
            $demande->reDemander();
            $this->em->flush();
            $this->addFlash('success', 'Nouvelle demande envoyée à ton coach !');
            return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
        }

        // Première demande → créer
        $labels = DemandeAccesPdf::LABELS_TYPE;
        $demande = new DemandeAccesPdf();
        $demande->setJoueur($joueur);
        $demande->setRencontre($rencontre);
        $demande->setTypePdf($type);

        $this->em->persist($demande);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Demande d\'accès envoyée pour "%s". Ton coach doit valider avant que tu puisses télécharger.',
            $labels[$type] ?? $type
        ));

        return $this->redirectToRoute('pirb_stats_match', ['id' => $rencontre->getId()]);
    }
}
