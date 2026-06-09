<?php

declare(strict_types=1);

namespace App\Controller\Pirb;

use App\Entity\Core\User;
use App\Gamification\BadgeCatalog;
use App\Repository\Sport\JoueurBadgeRepository;
use App\Repository\Sport\JoueurRepository;
use App\Service\JoueurPhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PirbProfilController — actions sur le profil joueur (PIRB Phase C+).
 *
 * Sépare la logique "profil" du PirbLoginController (SRP).
 * Pour l'instant : upload photo (#58). Plus tard : édition bio, num téléphone, etc.
 *
 * SÉCURITÉ :
 *   Chaque action vérifie que l'User authentifié est lié au Joueur cible
 *   (Joueur.user == current User). Un User ne peut JAMAIS modifier le profil
 *   d'un autre Joueur depuis PIRB. Pour les modifications par le staff, c'est
 *   côté Manager (avec ClubVoter).
 */
class PirbProfilController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upload de la photo de profil.
     * POST pirb.mabb.fr/profil/photo
     *
     * Le User connecté ne peut uploader QUE pour son propre Joueur lié.
     * Si pas de Joueur lié → flash message + redirect dashboard.
     */
    #[Route('/profil/photo', name: 'pirb_profil_photo_upload', methods: ['POST'])]
    public function uploadPhoto(
        Request $request,
        JoueurPhotoUploader $uploader,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        // CSRF — protège contre l'upload depuis un site tiers
        if (!$this->isCsrfTokenValid('pirb_photo_upload', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Recharge la page et réessaie.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Récupération du Joueur lié à l'User connecté
        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            $this->addFlash('error', 'Aucune fiche joueuse n\'est liée à ton compte. Contacte le staff.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('photo');
        if ($file === null || !$file->isValid()) {
            $this->addFlash('error', 'Aucun fichier reçu ou upload corrompu. Réessaie.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        try {
            $uploader->upload($file, $joueur);
            $this->em->flush();

            $this->logger->info('Photo profil PIRB mise à jour', [
                'user_id'   => $user->getId(),
                'joueur_id' => $joueur->getId(),
                'new_path'  => $joueur->getPhotoPath(),
            ]);

            $this->addFlash('success', 'Photo mise à jour avec succès ✓');
        } catch (\InvalidArgumentException $e) {
            // Validation MIME / taille
            $this->addFlash('error', $e->getMessage());
        } catch (FileException $e) {
            // Erreur physique (disque plein, permissions...)
            $this->logger->error('Échec upload photo PIRB', [
                'user_id' => $user->getId(),
                'error'   => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Erreur lors de l\'enregistrement. Réessaie ou contacte le staff.');
        }

        return $this->redirectToRoute('pirb_dashboard');
    }

    /**
     * Édition des infos perso de la joueuse (email + téléphone).
     * POST pirb.mabb.fr/profil/infos
     *
     * V1.1 minimaliste : email + téléphone. Un futur V1.1.1 ajoutera
     * contact d'urgence (champ dédié + migration).
     *
     * Sécurité identique à uploadPhoto : User ne peut éditer QUE son
     * Joueur lié (Joueur.user == current User).
     */
    #[Route('/profil/infos', name: 'pirb_profil_infos_update', methods: ['POST'])]
    public function updateInfos(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // CSRF
        if (!$this->isCsrfTokenValid('pirb_profil_infos', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide. Recharge la page et réessaie.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            $this->addFlash('error', 'Aucune fiche joueuse n\'est liée à ton compte.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // === Validation + nettoyage des champs ===
        $emailBrut = trim((string) $request->request->get('email', ''));
        $telBrut   = trim((string) $request->request->get('telephone', ''));

        // Email : null si vide, sinon validation format
        $emailNouveau = null;
        if ($emailBrut !== '') {
            if (!filter_var($emailBrut, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Email invalide. Format attendu : prenom.nom@example.com');
                return $this->redirectToRoute('pirb_dashboard');
            }
            $emailNouveau = strtolower($emailBrut);
        }

        // Téléphone : null si vide, sinon normalisation (strip espaces/points/tirets)
        $telNouveau = null;
        if ($telBrut !== '') {
            $telNorm = preg_replace('/[\s\.\-]/', '', $telBrut);
            // Format FR souple : 10 chiffres, ou +33 + 9 chiffres
            if (!preg_match('/^(0\d{9}|\+33\d{9})$/', $telNorm)) {
                $this->addFlash('error', 'Téléphone invalide. Format attendu : 06 12 34 56 78 ou +33 6 12 34 56 78');
                return $this->redirectToRoute('pirb_dashboard');
            }
            $telNouveau = $telNorm;
        }

        // === V1.2a — Champs scouting ===
        // Bio (texte libre, max ~500 chars pour rester lisible)
        $bioBrut = trim((string) $request->request->get('bio', ''));
        $bioNouvelle = $bioBrut === '' ? null : mb_substr($bioBrut, 0, 500);

        // Profil public (checkbox HTML : presence = true, absence = false)
        $profilPublicNouveau = (bool) $request->request->get('profilPublic');

        // Liens sociaux (5 réseaux fixes pour V1.2a)
        $liensBruts = [
            'instagram' => trim((string) $request->request->get('lien_instagram', '')),
            'tiktok'    => trim((string) $request->request->get('lien_tiktok', '')),
            'youtube'   => trim((string) $request->request->get('lien_youtube', '')),
            'twitter'   => trim((string) $request->request->get('lien_twitter', '')),
            'linkedin'  => trim((string) $request->request->get('lien_linkedin', '')),
        ];
        // Validation : chaque lien fourni doit être une URL valide
        foreach ($liensBruts as $reseau => $url) {
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                $this->addFlash('error', sprintf('Lien %s invalide. Format attendu : https://...', ucfirst($reseau)));
                return $this->redirectToRoute('pirb_dashboard');
            }
        }

        // Sauvegarde
        $joueur->setEmail($emailNouveau);
        $joueur->setTelephone($telNouveau);
        $joueur->setBio($bioNouvelle);
        $joueur->setProfilPublic($profilPublicNouveau);
        $joueur->setLiensSociaux($liensBruts); // le setter filtre les vides
        $this->em->flush();

        $this->logger->info('Infos profil PIRB mises à jour', [
            'user_id'   => $user->getId(),
            'joueur_id' => $joueur->getId(),
        ]);

        $this->addFlash('success', 'Infos mises à jour ✓');
        return $this->redirectToRoute('pirb_dashboard');
    }

    /**
     * Page d'édition des badges épinglés — V1.2b.
     * GET  pirb.mabb.fr/profil/badges  → grille des badges débloqués + checkboxes
     * POST pirb.mabb.fr/profil/badges  → enregistre la sélection (3 max)
     */
    #[Route('/profil/badges', name: 'pirb_profil_badges', methods: ['GET', 'POST'])]
    public function badges(
        Request $request,
        JoueurBadgeRepository $joueurBadgeRepo,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            $this->addFlash('error', 'Aucune fiche joueuse n\'est liée à ton compte.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // Tous les badges débloqués par cette joueuse (toutes saisons confondues).
        // codesBadgesPourJoueur() retourne les codes string.
        $codesDebloque = $joueurBadgeRepo->codesBadgesPourJoueur($joueur, null);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_profil_badges', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('pirb_profil_badges');
            }

            // Récupère la sélection : array de codes badges venant des checkboxes
            $selection = $request->request->all('badges');
            if (!is_array($selection)) {
                $selection = [];
            }

            // Filtre sécurité : ne garde QUE les codes que la joueuse a vraiment débloqués
            // (empêche un user de forger un code badge fictif via DevTools)
            $selectionValide = array_values(array_intersect($selection, $codesDebloque));

            // Limite max 3 (le setter de l'entité tronque aussi, double sécurité)
            if (count($selectionValide) > 3) {
                $this->addFlash('error', 'Tu peux épingler 3 badges maximum.');
                return $this->redirectToRoute('pirb_profil_badges');
            }

            $joueur->setBadgesEpingles($selectionValide === [] ? null : $selectionValide);
            $this->em->flush();

            $this->logger->info('Badges épinglés mis à jour', [
                'user_id'   => $user->getId(),
                'joueur_id' => $joueur->getId(),
                'badges'    => $selectionValide,
            ]);

            $this->addFlash('success', 'Tes badges épinglés sont à jour ✓');
            return $this->redirectToRoute('pirb_dashboard');
        }

        // GET : prépare les données pour le template (badges débloqués + catalogue)
        $catalogue = BadgeCatalog::all();
        $badgesDebloque = [];
        foreach ($codesDebloque as $code) {
            if (isset($catalogue[$code])) {
                $badgesDebloque[$code] = $catalogue[$code];
            }
        }

        return $this->render('pirb/badges.html.twig', [
            'joueur'              => $joueur,
            'badges_debloque'     => $badgesDebloque,
            'badges_epingles'     => $joueur->getBadgesEpingles() ?? [],
            'nb_max'              => 3,
        ]);
    }

    /**
     * Édition des highlights vidéo — V1.2c.
     * GET  pirb.mabb.fr/profil/highlights → form édition
     * POST pirb.mabb.fr/profil/highlights → sauvegarde (max 5 liens)
     */
    #[Route('/profil/highlights', name: 'pirb_profil_highlights', methods: ['GET', 'POST'])]
    public function highlights(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $joueur = $this->joueurRepo->findOneBy(['user' => $user]);
        if ($joueur === null) {
            $this->addFlash('error', 'Aucune fiche joueuse n\'est liée à ton compte.');
            return $this->redirectToRoute('pirb_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pirb_profil_highlights', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('pirb_profil_highlights');
            }

            // Récupère les arrays urls[] / titres[] / dates[] (form name= avec [])
            $urls   = $request->request->all('url');
            $titres = $request->request->all('titre');
            $dates  = $request->request->all('date');

            $items = [];
            foreach ((array) $urls as $i => $url) {
                $url = trim((string) $url);
                if ($url === '') continue;
                $items[] = [
                    'url'   => $url,
                    'titre' => trim((string) ($titres[$i] ?? '')),
                    'date'  => trim((string) ($dates[$i] ?? '')),
                ];
            }

            // Validation : URL valide pour chaque item
            foreach ($items as $i => $h) {
                if (!filter_var($h['url'], FILTER_VALIDATE_URL)) {
                    $this->addFlash('error', sprintf('Lien #%d invalide. Format attendu : https://...', $i + 1));
                    return $this->redirectToRoute('pirb_profil_highlights');
                }
            }

            $joueur->setHighlights($items === [] ? null : $items);
            $this->em->flush();

            $this->logger->info('Highlights PIRB mis à jour', [
                'user_id'   => $user->getId(),
                'joueur_id' => $joueur->getId(),
                'nb'        => count($items),
            ]);

            $this->addFlash('success', 'Highlights enregistrés ✓');
            return $this->redirectToRoute('pirb_dashboard');
        }

        return $this->render('pirb/highlights.html.twig', [
            'joueur'      => $joueur,
            'highlights'  => $joueur->getHighlights() ?? [],
            'nb_max'      => 5,
        ]);
    }
}
