<?php

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Entity\Sport\AffectationMatch;
use App\Entity\Sport\Presence;
use App\Repository\Sport\AffectationMatchRepository;
use App\Repository\Sport\CotisationJoueurRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Sport\PresenceRepository;
use App\Repository\Sport\RencontreRepository;
use App\Repository\Sport\RencontreRoleRepository;
use App\Repository\Sport\SeanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * PirbLoginController — authentification pour pirb.mabb.fr.
 *
 * Ce controller est routé uniquement sur le host pirb.mabb.fr
 * via config/routes/pirb.yaml (contrainte host).
 *
 * PIRB = espace joueur individuel : stats perso, shot chart, profil.
 * Accès : tout utilisateur authentifié (ROLE_USER minimum).
 */
class PirbLoginController extends AbstractController
{
    /**
     * Page de connexion — pirb.mabb.fr/login
     *
     * GET  → affiche le formulaire
     * POST → intercepté par le firewall "pirb" dans security.yaml
     */
    #[Route('/login', name: 'pirb_login')]
    public function login(AuthenticationUtils $authUtils): Response
    {
        // Déjà connecté → tableau de bord joueur
        if ($this->getUser()) {
            return $this->redirectToRoute('pirb_dashboard');
        }

        return $this->render('pirb/login.html.twig', [
            'last_username' => $authUtils->getLastUsername(),
            'error'         => $authUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Dashboard joueur — pirb.mabb.fr/
     *
     * V1 (PIRB MVP) : "Mon profil" lecture seule.
     *   - Récupère le Joueur lié à l'User connecté (via Joueur.user).
     *   - Affiche : prénom, nom, équipe, catégorie, numéro maillot, photo.
     *   - Affiche : statut cotisation de la saison en cours (dernière entrée).
     *   - Si pas de Joueur lié → message d'aide pour s'inscrire / contacter staff.
     *
     * Pas de denyAccessUnlessGranted ROLE_USER ici : le firewall PIRB
     * exige déjà ROLE_USER via access_control (cf. security.yaml).
     */
    #[Route('/', name: 'pirb_dashboard')]
    public function dashboard(
        JoueurRepository $joueurRepo,
        CotisationJoueurRepository $cotisationRepo,
        SeanceRepository $seanceRepo,
        RencontreRepository $rencontreRepo,
        RencontreRoleRepository $rencontreRoleRepo,
        PresenceRepository $presenceRepo,
        AffectationMatchRepository $affectationRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // V1.0 — Cherche le Joueur lié à ce User.
        $joueur = $joueurRepo->findOneBy(['user' => $user]);

        // Auto-lien par email : si pas encore lié ET qu'une fiche Joueur non liée
        // existe avec le même email, on lie automatiquement au 1er login.
        // Le staff n'a rien à faire — transparent pour la joueuse.
        if ($joueur === null && $user->getEmail() !== null) {
            $candidat = $joueurRepo->findAutoLinkByEmail($user->getEmail());
            if ($candidat !== null) {
                $candidat->setUser($user);
                $em->flush();
                $joueur = $candidat;
            }
        }

        $cotisation = null;
        $prochaines_seances = [];
        $prochaines_rencontres = [];
        $stats_presences = null;

        if ($joueur !== null) {
            // Cotisation saison courante
            $cotisation = $cotisationRepo->createQueryBuilder('c')
                ->where('c.joueur = :j')
                ->setParameter('j', $joueur)
                ->orderBy('c.saison', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            // V1.5 — Prochaines séances de l'équipe du joueur (3 max)
            if ($joueur->getEquipe() !== null) {
                $now = new \DateTimeImmutable();

                $prochaines_seances = $seanceRepo->createQueryBuilder('s')
                    ->where('s.equipe = :equipe')
                    ->andWhere('s.date >= :now')
                    ->setParameter('equipe', $joueur->getEquipe())
                    ->setParameter('now', $now)
                    ->orderBy('s.date', 'ASC')
                    ->setMaxResults(3)
                    ->getQuery()
                    ->getResult();

                $prochaines_rencontres = $rencontreRepo->createQueryBuilder('r')
                    ->where('r.equipe = :equipe')
                    ->andWhere('r.date >= :now')
                    ->setParameter('equipe', $joueur->getEquipe())
                    ->setParameter('now', $now)
                    ->orderBy('r.date', 'ASC')
                    ->setMaxResults(3)
                    ->getQuery()
                    ->getResult();
            }

            // V1.5 — Récap présences saison
            $presences = $presenceRepo->pourJoueur($joueur);
            $total = count($presences);
            $nbPresent = 0;
            foreach ($presences as $p) {
                /** @var Presence $p */
                if ($p->isPresent()) {
                    $nbPresent++;
                }
            }
            $stats_presences = [
                'total'     => $total,
                'present'   => $nbPresent,
                'absent'    => $total - $nbPresent,
                'taux'      => $total > 0 ? round($nbPresent / $total * 100, 0) : null,
            ];
        }

        // Rôles bénévoles de l'user sur les prochaines rencontres
        // (ex: marqueur, chrono) — pour afficher le badge "Inscrite" et
        // permettre la désinscription depuis le PIRB.
        $mes_roles_rencontres = !empty($prochaines_rencontres)
            ? $rencontreRoleRepo->findByUserForRencontres($user, $prochaines_rencontres)
            : [];

        // Staff assigné par match (AffectationMatch) — lecture seule pour le PIRB.
        // Permet aux joueuses de voir qui arbitre, qui fait la table, etc.
        // Format : [rencontre_id => [roleCode => AffectationMatch]]
        $staff_par_rencontre = [];
        foreach ($prochaines_rencontres as $rencontre) {
            $affMap = $affectationRepo->findByRencontre($rencontre);
            $staffActif = [];
            foreach ($affMap as $roleCode => $affList) {
                foreach ($affList as $aff) {
                    // On affiche uniquement les affectations effectives (pas les candidatures en attente)
                    if ($aff->isCouvert() || $aff->isAbsent()) {
                        $staffActif[$roleCode] = $aff;
                        break;
                    }
                }
            }
            if (!empty($staffActif)) {
                $staff_par_rencontre[$rencontre->getId()] = $staffActif;
            }
        }

        return $this->render('pirb/dashboard.html.twig', [
            'joueur'                => $joueur,
            'cotisation'            => $cotisation,
            'prochaines_seances'    => $prochaines_seances,
            'prochaines_rencontres' => $prochaines_rencontres,
            'mes_roles_rencontres'  => $mes_roles_rencontres,
            'staff_par_rencontre'   => $staff_par_rencontre,
            'stats_presences'       => $stats_presences,
        ]);
    }

    /**
     * Déconnexion — interceptée automatiquement par Symfony.
     * Cette méthode n'est jamais exécutée, la route doit juste exister.
     */
    #[Route('/deconnexion', name: 'pirb_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être appelée directement.');
    }
}
