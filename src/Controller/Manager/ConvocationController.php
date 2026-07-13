<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\Notification;
use App\Entity\Sport\Convocation;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Voter\ClubVoter;
use App\Service\ExpoPushService;
use App\Service\NotificationService;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ConvocationController — [13/07/2026] LE CHAÎNON MANQUANT.
 *
 * Constat de départ : la table `convocation` n'était écrite NULLE PART. Aucun
 * `new Convocation(...)` dans tout le projet. La fiche rencontre du Manager
 * affichait « Module convocations dans une prochaine itération ».
 *
 * Conséquence : l'espace joueuse (web ET app) lisait une table vide. L'écran de
 * convocations, l'API, le bloc « Ton prochain match » sur l'accueil : trois
 * tuyaux magnifiques branchés sur rien. Le coach ne pouvait pas convoquer.
 *
 * Ce contrôleur ferme la boucle : le coach coche son effectif, ça crée les
 * lignes, ça crée une notification, la joueuse la voit dans l'app et répond.
 *
 *   POST /manager/rencontres/{id}/convocations → enregistre la liste convoquée
 *
 * DEUX RÈGLES MÉTIER, ET ELLES NE SONT PAS ARBITRAIRES :
 *
 *  1. DÉCOCHER NE SUPPRIME PAS UNE RÉPONSE. Si une joueuse a déjà répondu
 *     (présente, absente, incertaine) et que le coach la retire de la liste, on
 *     supprime la convocation : c'est son droit, l'effectif change. Mais on le
 *     LOGGE, parce qu'effacer la réponse de quelqu'un sans trace, c'est le genre
 *     de chose qu'on regrette le jour d'un litige.
 *
 *  2. ON NE RE-NOTIFIE PAS UNE JOUEUSE DÉJÀ CONVOQUÉE. Le coach va rouvrir cette
 *     page dix fois pour ajuster sa liste. Seules les NOUVELLES convocations
 *     déclenchent une notification. Sans ça, on spamme, et une notification
 *     qu'on spamme est une notification qu'on ignore.
 */
class ConvocationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConvocationRepository $convocationRepo,
        private readonly JoueurRepository $joueurRepo,
        private readonly NotificationService $notifService,
        private readonly SaisonService $saisonService,
        private readonly ExpoPushService $pushService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route(
        '/rencontres/{id}/convocations',
        name: 'manager_rencontre_convocations',
        methods: ['POST'],
        requirements: ['id' => '\d+'],
    )]
    public function enregistrer(Request $request, Rencontre $rencontre): Response
    {
        // Le staff du club, et personne d'autre. Une convocation engage le club.
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $rencontre);

        if (!$this->isCsrfTokenValid('convocations_' . $rencontre->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        $club = $rencontre->getClub();
        $equipe = $rencontre->getEquipe();
        if ($club === null || $equipe === null) {
            $this->addFlash('error', "Cette rencontre n'a ni club ni équipe : impossible de convoquer.");
            return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
        }

        // L'effectif de l'équipe sur la saison de la rencontre. On ne convoque
        // JAMAIS depuis une liste envoyée par le client : on part de l'effectif
        // réel et on ne garde que les ids qui en font partie. Sinon, en bricolant
        // le formulaire, on convoquerait une joueuse d'un autre club.
        $saison = $rencontre->getSaison() ?? $this->saisonService->getSaisonCourante();
        $effectif = $this->joueurRepo->findByEquipeAffectation($equipe, $saison);

        /** @var array<int, Joueur> $parId */
        $parId = [];
        foreach ($effectif as $joueur) {
            $parId[$joueur->getId()] = $joueur;
        }

        $coches = array_map('intval', (array) $request->request->all('joueurs'));
        $coches = array_values(array_intersect($coches, array_keys($parId))); // filtre de sécurité

        // Les convocations existantes, indexées par joueur.
        $existantes = $this->convocationRepo->findBy(['rencontre' => $rencontre]);
        /** @var array<int, Convocation> $dejaConvoquees */
        $dejaConvoquees = [];
        foreach ($existantes as $c) {
            $idJoueur = $c->getJoueur()?->getId();
            if ($idJoueur !== null) {
                $dejaConvoquees[$idJoueur] = $c;
            }
        }

        $ajoutees = 0;
        $retirees = 0;
        /** @var \App\Entity\Core\User[] $aPrevenir Les NOUVELLES convoquées, à pousser après le flush. */
        $aPrevenir = [];
        $texteNotif = '';

        // ── AJOUTS ──────────────────────────────────────────────────────────
        foreach ($coches as $idJoueur) {
            if (isset($dejaConvoquees[$idJoueur])) {
                continue; // déjà convoquée : on ne touche à rien, on ne re-notifie pas
            }

            $joueur = $parId[$idJoueur];

            $convocation = new Convocation();
            $convocation->setRencontre($rencontre);
            $convocation->setJoueur($joueur);
            $this->em->persist($convocation);
            $ajoutees++;

            // La notification : c'est elle qui rendra le push utile (bloc K).
            // Pas de compte utilisateur rattaché ? Pas de notification, mais la
            // convocation existe quand même (le coach verra la réponse au tel).
            $user = $joueur->getUser();
            if ($user !== null) {
                $quand = $rencontre->getDate()?->format('d/m à H\hi') ?? 'date à confirmer';
                $contre = $rencontre->getAdversaire() ?? 'adversaire à confirmer';
                $ou = $rencontre->isDomicile() ? 'à domicile' : 'à l\'extérieur';
                $texteNotif = sprintf('Tu es convoquée %s, %s, %s. Réponds vite.', $quand, $contre, $ou);

                $this->notifService->creer(
                    $user,
                    $club,
                    Notification::TYPE_CONVOCATION,
                    message: $texteNotif,
                    lienRoute: 'pirb_convocations',
                );

                // Le push part APRÈS le flush (voir plus bas) : on ne prévient
                // jamais quelqu'un d'une convocation qui n'a pas été enregistrée.
                $aPrevenir[] = $user;
            }
        }

        // ── RETRAITS ────────────────────────────────────────────────────────
        foreach ($dejaConvoquees as $idJoueur => $convocation) {
            if (in_array($idJoueur, $coches, true)) {
                continue;
            }

            // On trace : effacer la réponse de quelqu'un sans trace, c'est ce
            // qu'on regrette le jour d'un litige.
            if ($convocation->getReponse() !== null) {
                $this->logger->warning('Convocation supprimée alors qu\'une réponse existait', [
                    'convocation_id' => $convocation->getId(),
                    'rencontre_id'   => $rencontre->getId(),
                    'joueur_id'      => $idJoueur,
                    'reponse'        => $convocation->getReponse(),
                ]);
            }

            $this->em->remove($convocation);
            $retirees++;
        }

        // Un seul flush : convocations + notifications dans la même transaction
        // (c'est la convention de NotificationService, qui persiste sans flusher).
        $this->em->flush();

        // ── PUSH ────────────────────────────────────────────────────────────
        // APRÈS le flush, et seulement pour les NOUVELLES convoquées. L'ordre
        // n'est pas cosmétique : on ne notifie jamais quelqu'un d'une convocation
        // qui n'existe pas encore en base. Et si Expo est en panne, le service
        // logge et n'échoue pas : la convocation reste enregistrée.
        if ($aPrevenir !== []) {
            $this->pushService->envoyerAUsers(
                $aPrevenir,
                '🏀 Tu es convoquée',
                $texteNotif,
                ['type' => 'convocation'], // lu par l'app au tap pour ouvrir le bon écran
            );
        }

        $this->logger->info('Convocations mises à jour', [
            'rencontre_id' => $rencontre->getId(),
            'convoquees'   => count($coches),
            'ajoutees'     => $ajoutees,
            'retirees'     => $retirees,
        ]);

        $this->addFlash('success', sprintf(
            '%d joueuse%s convoquée%s. %d ajout%s, %d retrait%s.',
            count($coches),
            count($coches) > 1 ? 's' : '',
            count($coches) > 1 ? 's' : '',
            $ajoutees,
            $ajoutees > 1 ? 's' : '',
            $retirees,
            $retirees > 1 ? 's' : '',
        ));

        return $this->redirectToRoute('manager_rencontre_show', ['id' => $rencontre->getId()]);
    }
}
