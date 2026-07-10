<?php

declare(strict_types=1);

namespace App\Controller\Vitrine;

use App\Entity\Sport\PreInscription;
use App\Repository\Core\ClubRepository;
use App\Repository\Sport\PreInscriptionRepository;
use App\Repository\Sport\SecteurRepository;
use App\Service\SaisonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pré-inscription licence — formulaire PUBLIC de la vitrine [V2.4h].
 *
 * Remplace le Google Form « Formulaire licence » : le parent (ou la
 * joueuse majeure) dépose sa demande ici ; la secrétaire la convertit
 * depuis Manager → /secretariat/pre-inscriptions. AUCUNE donnée n'est
 * visible publiquement après dépôt.
 *
 * Anti-spam SANS stockage d'IP (minimisation RGPD) :
 *   - champ honeypot invisible (`site_web`) : rempli = bot → rejet muet ;
 *   - garde-fou global : max 30 dépôts / heure pour le club.
 * RGPD : consentement explicite obligatoire (case non pré-cochée),
 * horodaté (RGPD-0006/RGPD-0012), lien politique de confidentialité.
 */
class PreInscriptionPubliqueController extends AbstractController
{
    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly SecteurRepository $secteurRepository,
        private readonly PreInscriptionRepository $preInscriptionRepository,
        private readonly SaisonService $saisonService,
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'app.club_vitrine_slug')]
        private readonly string $clubVitrineSlug,
    ) {}

    #[Route('/pre-inscription', name: 'vitrine_pre_inscription', methods: ['GET', 'POST'])]
    public function preInscription(Request $request): Response
    {
        $club = $this->clubRepository->findOneBy(['slug' => $this->clubVitrineSlug]);
        if ($club === null) {
            throw $this->createNotFoundException('Club de la vitrine introuvable.');
        }
        $saison   = $this->saisonService->getSaisonCourante();
        $secteurs = $this->secteurRepository->findByClub($club);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('pre_inscription', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expirée — merci de renvoyer le formulaire.');
                return $this->redirectToRoute('vitrine_pre_inscription');
            }

            // Honeypot : un humain ne voit pas ce champ, un bot le remplit.
            if (trim((string) $request->request->get('site_web', '')) !== '') {
                // Rejet silencieux (ne pas aider les bots à se corriger)
                return $this->redirectToRoute('vitrine_pre_inscription', ['ok' => 1]);
            }
            // Garde-fou volumétrique global (pas d'IP stockée)
            if ($this->preInscriptionRepository->compterRecentes($club, new \DateTimeImmutable('-1 hour')) >= 30) {
                $this->addFlash('error', 'Trop de demandes en ce moment — réessaie dans une heure ou contacte le club.');
                return $this->redirectToRoute('vitrine_pre_inscription');
            }

            $nom    = trim((string) $request->request->get('nom', ''));
            $prenom = trim((string) $request->request->get('prenom', ''));
            $consentement = $request->request->getBoolean('consentement');

            if ($nom === '' || $prenom === '') {
                $this->addFlash('error', 'Le nom et le prénom de la joueuse sont obligatoires.');
                return $this->redirectToRoute('vitrine_pre_inscription');
            }
            if (!$consentement) {
                $this->addFlash('error', 'Le consentement au traitement des données est obligatoire pour envoyer la demande.');
                return $this->redirectToRoute('vitrine_pre_inscription');
            }

            $pre = new PreInscription();
            $pre->setClub($club)
                ->setSaison($saison)
                ->setNom($nom)
                ->setPrenom($prenom)
                ->setCategorie((string) $request->request->get('categorie', ''))
                ->setTelephoneJoueuse((string) $request->request->get('telephone_joueuse', ''))
                ->setSecteurSouhaite((string) $request->request->get('secteur', ''))
                ->setParentNom((string) $request->request->get('parent_nom', ''))
                ->setParentTelephone((string) $request->request->get('parent_telephone', ''))
                ->setParentEmail((string) $request->request->get('parent_email', ''))
                ->setParentAdresse((string) $request->request->get('parent_adresse', ''))
                ->setParentCodePostal((string) $request->request->get('parent_code_postal', ''))
                ->setConsentementAt(new \DateTimeImmutable());

            $ddn = trim((string) $request->request->get('date_naissance', ''));
            if ($ddn !== '') {
                try { $pre->setDateNaissance(new \DateTimeImmutable($ddn)); } catch (\Exception) {}
            }

            $this->em->persist($pre);
            $this->em->flush();

            return $this->redirectToRoute('vitrine_pre_inscription', ['ok' => 1]);
        }

        return $this->render('vitrine/pre_inscription.html.twig', [
            'club'     => $club,
            'saison'   => $saison,
            'secteurs' => $secteurs,
            'envoye'   => $request->query->getBoolean('ok'),
        ]);
    }
}
