<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Notification;
use App\Entity\Core\User;
use App\Entity\Sport\Convocation;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use App\Repository\Sport\ConvocationRepository;
use App\Repository\Sport\JoueurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * [VC-3 04/08/2026] La logique de convocation, à un seul endroit.
 *
 * POURQUOI CE SERVICE EXISTE :
 *   Les règles de convocation vivaient dans ConvocationController, mêlées au
 *   formulaire, au jeton CSRF, aux messages flash et à la redirection. L'app
 *   mobile a besoin des MÊMES règles, sans rien de tout ça.
 *   Recopier le code aurait garanti la divergence : le projet en a déjà fait
 *   les frais (deux agrégateurs de stats qui font presque la même chose).
 *   Donc : la logique ici, les contrôleurs se contentent de traduire l'entrée
 *   et la sortie.
 *
 * LES DEUX RÈGLES MÉTIER, INCHANGÉES :
 *
 *  1. DÉCOCHER SUPPRIME LA CONVOCATION, MÊME RÉPONDUE — mais on le JOURNALISE.
 *     Le coach a le droit de changer son effectif. Effacer la réponse de
 *     quelqu'un sans laisser de trace, c'est ce qu'on regrette le jour d'un
 *     litige.
 *
 *  2. ON NE RE-NOTIFIE JAMAIS UNE JOUEUSE DÉJÀ CONVOQUÉE. Le coach rouvrira
 *     sa liste dix fois pour l'ajuster. Seules les NOUVELLES convocations
 *     déclenchent notification et push. Une notification qu'on spamme est une
 *     notification qu'on ignore.
 *
 * SÉCURITÉ — la règle qui ne se négocie pas :
 *   On ne convoque jamais depuis une liste envoyée par le client. On part de
 *   l'effectif RÉEL de l'équipe et on ne garde que les identifiants qui en
 *   font partie. Sans ce filtre, un client bricolé convoquerait une joueuse
 *   d'un autre club — et exposerait son nom en retour.
 *
 * ORDRE DES OPÉRATIONS :
 *   persist → flush → push. Le push part APRÈS l'enregistrement : on ne
 *   prévient jamais quelqu'un d'une convocation qui n'existe pas encore.
 */
final class ConvocationManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConvocationRepository $convocationRepository,
        private readonly JoueurRepository $joueurRepository,
        private readonly NotificationService $notificationService,
        private readonly SaisonService $saisonService,
        private readonly ExpoPushService $pushService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * L'effectif convocable pour cette rencontre.
     *
     * C'est la source de vérité des deux côtés : c'est cette liste que l'app
     * affiche, et c'est contre elle que les identifiants reçus sont filtrés.
     *
     * @return array<int, Joueur> indexé par identifiant de joueuse
     */
    public function effectifConvocable(Rencontre $rencontre): array
    {
        $equipe = $rencontre->getEquipe();
        if ($equipe === null) {
            return [];
        }

        $saison = $rencontre->getSaison() ?? $this->saisonService->getSaisonCourante();

        $parId = [];
        foreach ($this->joueurRepository->findByEquipeAffectation($equipe, $saison) as $joueur) {
            $parId[(int) $joueur->getId()] = $joueur;
        }
        return $parId;
    }

    /**
     * Les convocations existantes d'une rencontre, indexées par joueuse.
     *
     * @return array<int, Convocation>
     */
    public function convocationsExistantes(Rencontre $rencontre): array
    {
        $parJoueur = [];
        foreach ($this->convocationRepository->findBy(['rencontre' => $rencontre]) as $c) {
            $id = $c->getJoueur()?->getId();
            if ($id !== null) {
                $parJoueur[(int) $id] = $c;
            }
        }
        return $parJoueur;
    }

    /**
     * Applique une liste de convoquées : crée les manquantes, retire les autres.
     *
     * @param int[] $idsSouhaites identifiants bruts venus du client (non filtrés)
     * @return array{convoquees: int, ajoutees: int, retirees: int, notifiees: int}
     */
    public function appliquer(Rencontre $rencontre, array $idsSouhaites): array
    {
        $club = $rencontre->getClub();
        if ($club === null || $rencontre->getEquipe() === null) {
            throw new \InvalidArgumentException(
                "Cette rencontre n'a ni club ni équipe : impossible de convoquer."
            );
        }

        $effectif = $this->effectifConvocable($rencontre);

        // LE filtre de sécurité : on ne garde que ce qui appartient à l'effectif.
        $retenus = array_values(array_intersect(
            array_map('intval', $idsSouhaites),
            array_keys($effectif)
        ));

        $existantes = $this->convocationsExistantes($rencontre);

        $ajoutees = 0;
        $retirees = 0;
        /** @var User[] $aPrevenir */
        $aPrevenir = [];
        $texteNotif = '';

        // ── AJOUTS ──────────────────────────────────────────────────────────
        foreach ($retenus as $idJoueur) {
            if (isset($existantes[$idJoueur])) {
                continue; // déjà convoquée : on ne touche à rien, on ne re-notifie pas
            }

            $joueur = $effectif[$idJoueur];

            $convocation = new Convocation();
            $convocation->setRencontre($rencontre);
            $convocation->setJoueur($joueur);
            $this->em->persist($convocation);
            $ajoutees++;

            // Pas de compte rattaché ? Pas de notification, mais la convocation
            // existe quand même (le coach préviendra par téléphone).
            $user = $joueur->getUser();
            if ($user !== null) {
                $texteNotif = $this->texteConvocation($rencontre);
                $this->notificationService->creer(
                    $user,
                    $club,
                    Notification::TYPE_CONVOCATION,
                    message: $texteNotif,
                    lienRoute: 'pirb_convocations',
                );
                $aPrevenir[] = $user;
            }
        }

        // ── RETRAITS ────────────────────────────────────────────────────────
        foreach ($existantes as $idJoueur => $convocation) {
            if (in_array($idJoueur, $retenus, true)) {
                continue;
            }

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
        // (NotificationService persiste sans flusher, c'est sa convention).
        $this->em->flush();

        // ── PUSH, après le flush et pour les NOUVELLES seulement ─────────────
        // Si Expo est en panne, le service journalise et n'échoue pas : la
        // convocation reste enregistrée.
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
            'convoquees'   => count($retenus),
            'ajoutees'     => $ajoutees,
            'retirees'     => $retirees,
        ]);

        return [
            'convoquees' => count($retenus),
            'ajoutees'   => $ajoutees,
            'retirees'   => $retirees,
            'notifiees'  => count($aPrevenir),
        ];
    }

    /** Le message vu par la joueuse. Un seul endroit, web et app identiques. */
    private function texteConvocation(Rencontre $rencontre): string
    {
        $quand  = $rencontre->getDate()?->format('d/m à H\hi') ?? 'date à confirmer';
        $contre = $rencontre->getAdversaire() ?? 'adversaire à confirmer';
        $ou     = $rencontre->isDomicile() ? 'à domicile' : 'à l\'extérieur';

        return sprintf('Tu es convoquée %s, %s, %s. Réponds vite.', $quand, $contre, $ou);
    }
}
