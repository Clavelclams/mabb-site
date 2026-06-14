<?php

declare(strict_types=1);

namespace App\Controller\Manager;

use App\Entity\Core\User;
use App\Entity\Sport\BulletinScolaire;
use App\Entity\Sport\Joueur;
use App\Repository\Sport\BulletinScolaireRepository;
use App\Repository\Sport\JoueurRepository;
use App\Security\Voter\ClubVoter;
use App\Service\SectionSportive\BulletinImporter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * [B33 12/06/2026] Section Sportive — bulletins + bilan scolaire.
 *
 * PERMISSIONS :
 *   - Voir bulletin d'une joueuse : joueuse + parent lié + staff Section Sportive
 *   - Upload : parent (de la joueuse) ou staff
 *   - Tagguer joueur.estSectionSportive : staff uniquement
 *
 * Toutes les pages exigent que joueur.estSectionSportive = true.
 */
class SectionSportiveController extends AbstractController
{
    public function __construct(
        private readonly JoueurRepository $joueurRepo,
        private readonly BulletinScolaireRepository $bulletinRepo,
        private readonly BulletinImporter $importer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Bilan scolaire d'une joueuse Section Sportive.
     * Fromage de stats (radar) + suivi T1/T2/T3.
     */
    #[Route('/joueuses/{id}/bilan-scolaire', name: 'manager_section_sportive_bilan', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function bilan(Joueur $joueur): Response
    {
        if (!$joueur->isEstSectionSportive()) {
            $this->addFlash('warning', 'Cette joueuse n\'est pas en Section Sportive.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        $bulletins = $this->bulletinRepo->findForJoueur($joueur);

        // Agrégation pour le radar : moyenne par matière sur la dernière année
        $radarData = $this->computeRadarData($bulletins);

        // Suivi progression : moyenne générale par (année, trimestre)
        $progression = array_map(static fn(BulletinScolaire $b) => [
            'annee'    => $b->getAnneeScolaire(),
            'trimestre' => $b->getTrimestre(),
            'moyenne'  => $b->getMoyenneGenerale(),
            'label'    => $b->getAnneeScolaire() . ' ' . $b->getTrimestre(),
        ], $bulletins);
        $progression = array_reverse($progression); // chronologique

        return $this->render('manager/section_sportive/bilan.html.twig', [
            'joueur'      => $joueur,
            'bulletins'   => $bulletins,
            'radar_data'  => $radarData,
            'progression' => $progression,
        ]);
    }

    /**
     * Upload manuel d'un bulletin (staff ou parent).
     */
    #[Route('/joueuses/{id}/bilan-scolaire/uploader', name: 'manager_section_sportive_upload', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function upload(Request $request, Joueur $joueur): Response
    {
        if (!$joueur->isEstSectionSportive()) {
            $this->addFlash('warning', 'Cette joueuse n\'est pas en Section Sportive.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('upload_bulletin_' . $joueur->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton CSRF invalide.');
                return $this->redirectToRoute('manager_section_sportive_upload', ['id' => $joueur->getId()]);
            }

            $annee = (string) $request->request->get('annee_scolaire', '');
            $trim = (string) $request->request->get('trimestre', '');
            $moyGen = $request->request->get('moyenne_generale') !== '' ? (float) $request->request->get('moyenne_generale') : null;
            $appreciation = trim((string) $request->request->get('appreciation', '')) ?: null;

            if (!preg_match('/^\d{4}-\d{4}$/', $annee) || !in_array($trim, BulletinScolaire::TRIMESTRES, true)) {
                $this->addFlash('error', 'Année (format 2026-2027) ou trimestre (T1/T2/T3) invalide.');
                return $this->redirectToRoute('manager_section_sportive_upload', ['id' => $joueur->getId()]);
            }

            // Parse notes saisies (1 par ligne : "Matière | Moyenne | Coef")
            $notes = $this->parseNotes((string) $request->request->get('notes_texte', ''));

            /** @var User $user */
            $user = $this->getUser();
            $this->importer->createManuelle($joueur, $annee, $trim, null, $moyGen, $appreciation, $notes, $user);

            $this->addFlash('success', "Bulletin {$annee} {$trim} enregistré pour {$joueur->getPrenom()}.");
            return $this->redirectToRoute('manager_section_sportive_bilan', ['id' => $joueur->getId()]);
        }

        return $this->render('manager/section_sportive/upload.html.twig', [
            'joueur' => $joueur,
        ]);
    }

    /**
     * Toggle tag Section Sportive sur la joueuse (staff).
     */
    #[Route('/joueuses/{id}/toggle-section-sportive', name: 'manager_section_sportive_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(Request $request, Joueur $joueur): Response
    {
        $this->denyAccessUnlessGranted(ClubVoter::CLUB_STAFF, $joueur);

        if (!$this->isCsrfTokenValid('toggle_section_sportive_' . $joueur->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
        }

        $joueur->setEstSectionSportive(!$joueur->isEstSectionSportive());
        $classe = trim((string) $request->request->get('classe_scolaire', '')) ?: null;
        if ($classe !== null) {
            $joueur->setClasseScolaire($classe);
        }

        $this->em->flush();
        $this->addFlash('success', $joueur->isEstSectionSportive() ? '✅ Section Sportive activée' : 'Section Sportive désactivée');
        return $this->redirectToRoute('manager_joueur_show', ['id' => $joueur->getId()]);
    }

    /**
     * Compute le radar : pour la dernière année, moyenne par matière sur les 3 trimestres.
     *
     * @param BulletinScolaire[] $bulletins
     */
    private function computeRadarData(array $bulletins): array
    {
        if (empty($bulletins)) return [];

        // Dernière année
        $derniereAnnee = $bulletins[0]->getAnneeScolaire();
        $bulletinsAnnee = array_filter($bulletins, fn(BulletinScolaire $b) => $b->getAnneeScolaire() === $derniereAnnee);

        $sumsByMatiere = [];
        $countsByMatiere = [];

        foreach ($bulletinsAnnee as $bulletin) {
            foreach ($bulletin->getNotes() as $note) {
                if ($note->getMoyenne() === null) continue;
                $m = $note->getMatiere();
                $sumsByMatiere[$m] = ($sumsByMatiere[$m] ?? 0) + $note->getMoyenne();
                $countsByMatiere[$m] = ($countsByMatiere[$m] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($sumsByMatiere as $matiere => $sum) {
            $result[] = [
                'matiere' => $matiere,
                'moyenne' => round($sum / $countsByMatiere[$matiere], 2),
            ];
        }

        return $result;
    }

    /**
     * Parse les notes saisies au format texte : "Matière | Moyenne | Coef" par ligne.
     */
    private function parseNotes(string $texte): array
    {
        $notes = [];
        foreach (preg_split('/\r\n|\r|\n/', $texte) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') continue;
            $parts = array_map('trim', explode('|', $ligne));
            if (count($parts) < 1) continue;
            $notes[] = [
                'matiere' => $parts[0] ?? '',
                'moyenne' => isset($parts[1]) && $parts[1] !== '' ? (float) $parts[1] : null,
                'coef'    => isset($parts[2]) ? (int) $parts[2] : 1,
                'appreciation' => $parts[3] ?? null,
            ];
        }
        return $notes;
    }
}
