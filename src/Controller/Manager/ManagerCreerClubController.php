<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Core\UserClubRole;
use App\Repository\Core\ClubRepository;
use App\Security\Tenant\TenantResolver;
use App\Service\ClubOfficialisation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Création d'un club (multi-club, Lot 2b-2).
 *
 * N'importe qui peut créer un club — connecté ou non :
 *   • si anonyme → on crée aussi son compte User (rôle plateforme ROLE_DIRIGEANT) ;
 *   • si déjà connecté → on réutilise son compte, on lui ajoute ROLE_DIRIGEANT.
 * Le créateur devient AUTOMATIQUEMENT administrateur de son club via un
 * UserClubRole DIRIGEANT actif (STATUS_ACTIVE) — c'est ce rôle métier qui donne
 * les droits d'admin (cf. ClubVoter::CLUB_ADMIN).
 *
 * Officialisation : le club est marqué OFFICIEL si son numéro FFBB figure dans
 * le référentiel importé (ClubOfficialisation). Sinon il reste NON-OFFICIEL,
 * avec exactement les mêmes fonctionnalités — juste pas le badge.
 *
 * Route publique : cf. security.yaml (^/creer-un-club$ → PUBLIC_ACCESS).
 */
class ManagerCreerClubController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepository,
        private readonly SluggerInterface $slugger,
        private readonly ClubOfficialisation $officialisation,
        private readonly TenantResolver $tenantResolver,
    ) {
    }

    #[Route('/creer-un-club', name: 'manager_creer_club', methods: ['GET', 'POST'])]
    public function creer(
        Request $request,
        UserPasswordHasherInterface $hasher,
        Security $security,
    ): Response {
        $utilisateur   = $this->getUser();
        $dejaConnecte  = $utilisateur instanceof User;

        // Valeurs pour ré-affichage en cas d'erreur (jamais le mot de passe).
        $valeurs = [
            'nom'         => '',
            'discipline'  => '',
            'ville'       => '',
            'code_postal' => '',
            'numero_ffbb' => '',
            'prenom'      => '',
            'nom_user'    => '',
            'email'       => '',
        ];

        if ($request->isMethod('POST')) {
            $erreurs = [];

            if (!$this->isCsrfTokenValid('creer_club', (string) $request->request->get('_token'))) {
                $erreurs[] = 'Session expirée, merci de renvoyer le formulaire.';
            }

            // --- Champs club ---
            $nom        = trim((string) $request->request->get('nom', ''));
            $discipline = (string) $request->request->get('discipline', '');
            $ville      = trim((string) $request->request->get('ville', ''));
            $codePostal = trim((string) $request->request->get('code_postal', ''));
            $numeroFfbb = trim((string) $request->request->get('numero_ffbb', ''));

            $valeurs['nom']         = $nom;
            $valeurs['discipline']  = $discipline;
            $valeurs['ville']       = $ville;
            $valeurs['code_postal'] = $codePostal;
            $valeurs['numero_ffbb'] = $numeroFfbb;

            if ($nom === '') {
                $erreurs[] = 'Le nom du club est obligatoire.';
            }
            if (!in_array($discipline, Club::DISCIPLINES, true)) {
                $erreurs[] = 'Choisis une discipline.';
            }

            // --- Compte (uniquement si anonyme) ---
            $prenom = $nomUser = $email = '';
            $motDePasse = '';
            if (!$dejaConnecte) {
                $prenom     = trim((string) $request->request->get('prenom', ''));
                $nomUser    = trim((string) $request->request->get('nom_user', ''));
                $email      = trim((string) $request->request->get('email', ''));
                $motDePasse = (string) $request->request->get('password', '');
                $rgpd       = $request->request->get('rgpd');

                $valeurs['prenom']   = $prenom;
                $valeurs['nom_user'] = $nomUser;
                $valeurs['email']    = $email;

                if ($prenom === '' || $nomUser === '') {
                    $erreurs[] = 'Ton prénom et ton nom sont obligatoires.';
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $erreurs[] = 'Adresse email invalide.';
                }
                if (strlen($motDePasse) < 8) {
                    $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
                }
                if (!$rgpd) {
                    $erreurs[] = 'Tu dois accepter la politique de confidentialité.';
                }
                if ($email !== '' && $this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
                    $erreurs[] = 'Un compte existe déjà avec cet email. Connecte-toi d\'abord, puis reviens créer ton club.';
                }
            }

            // --- Anti-doublon FFBB (un numéro = un seul club) ---
            if ($numeroFfbb !== '') {
                $numeroNormalise = strtoupper($numeroFfbb);
                if ($this->clubRepository->findOneBy(['numeroFfbb' => $numeroNormalise]) !== null) {
                    $erreurs[] = 'Ce numéro FFBB est déjà utilisé par un club sur la plateforme. Rejoins-le plutôt que d\'en créer un doublon.';
                }
            }

            if ($erreurs === []) {
                // 1) User : créé si anonyme, sinon réutilisé + promu ROLE_DIRIGEANT.
                if (!$dejaConnecte) {
                    $user = (new User())
                        ->setPrenom($prenom)
                        ->setNom($nomUser)
                        ->setEmail($email)
                        ->setRgpdConsent(true)
                        ->setRoles(['ROLE_DIRIGEANT']);
                    $user->setPassword($hasher->hashPassword($user, $motDePasse));
                    $this->em->persist($user);
                } else {
                    /** @var User $user */
                    $user = $utilisateur;
                    if (!in_array('ROLE_DIRIGEANT', $user->getRoles(), true)) {
                        // getRoles() ré-ajoute ROLE_USER automatiquement : on le retire
                        // du set pour ne pas le persister en dur, on garde le reste.
                        $rolesExistants = array_filter($user->getRoles(), static fn (string $r): bool => $r !== 'ROLE_USER');
                        $user->setRoles(array_values(array_unique([...$rolesExistants, 'ROLE_DIRIGEANT'])));
                    }
                }

                // 2) Club + slug unique + officialisation.
                $club = (new Club())
                    ->setNom($nom)
                    ->setDiscipline($discipline)
                    ->setSlug($this->genererSlugUnique($nom))
                    ->setNumeroFfbb($numeroFfbb !== '' ? $numeroFfbb : null)
                    ->setCreateur($user);
                if ($ville !== '') {
                    $club->setVille($ville);
                }
                if ($codePostal !== '') {
                    $club->setCodePostal($codePostal);
                }
                $this->officialisation->rafraichir($club); // pose isOfficiel selon le référentiel FFBB
                $this->em->persist($club);

                // 3) Le créateur devient admin de SON club : UserClubRole DIRIGEANT actif.
                $adhesion = (new UserClubRole())
                    ->setUser($user)
                    ->setClub($club)
                    ->setRole(UserClubRole::ROLE_DIRIGEANT)
                    ->setStatus(UserClubRole::STATUS_ACTIVE);
                $this->em->persist($adhesion);

                $this->em->flush();

                // 4) Connexion (si nouveau compte) + sélection du club + redirection.
                if (!$dejaConnecte) {
                    $security->login($user, 'form_login', 'manager');
                }
                $this->tenantResolver->setCurrentClub($club);

                $this->addFlash('success', $club->isOfficiel()
                    ? sprintf('Club « %s » créé et reconnu OFFICIEL FFBB. Tu en es l\'administrateur.', $club->getNom())
                    : sprintf('Club « %s » créé (non-officiel pour l\'instant). Tu en es l\'administrateur, toutes les fonctionnalités sont dispo.', $club->getNom())
                );

                return $this->redirectToRoute('manager_dashboard');
            }

            return $this->render('manager/creer_club.html.twig', [
                'erreurs'       => $erreurs,
                'valeurs'       => $valeurs,
                'deja_connecte' => $dejaConnecte,
                'disciplines'   => Club::DISCIPLINE_LIBELLES,
            ]);
        }

        return $this->render('manager/creer_club.html.twig', [
            'erreurs'       => [],
            'valeurs'       => $valeurs,
            'deja_connecte' => $dejaConnecte,
            'disciplines'   => Club::DISCIPLINE_LIBELLES,
        ]);
    }

    /**
     * Slug unique à partir du nom du club : on translittère, on met en minuscules,
     * puis on suffixe -2, -3… tant qu'un club porte déjà ce slug.
     */
    private function genererSlugUnique(string $nom): string
    {
        $base = strtolower($this->slugger->slug($nom)->toString());
        if ($base === '') {
            $base = 'club';
        }

        $slug = $base;
        $i    = 2;
        while ($this->clubRepository->findOneBy(['slug' => $slug]) !== null) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
