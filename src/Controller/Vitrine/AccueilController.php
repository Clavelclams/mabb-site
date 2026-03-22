<?php

namespace App\Controller\Vitrine;

use App\Repository\Vitrine\ArticleRepository;
use App\Repository\Vitrine\MediaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

final class AccueilController extends AbstractController
{
    #[Route('/', name: 'vitrine_accueil')]
    public function index(ArticleRepository $articleRepo): Response
    {
        return $this->render('vitrine/accueil/index.html.twig', [
            'dernieres_actus' => $articleRepo->findDerniersPublies(3),
        ]);
    }

    #[Route('/news', name: 'vitrine_news')]
    public function news(Request $request, ArticleRepository $articleRepo): Response
    {
        $page     = max(1, $request->query->getInt('page', 1));
        $articles = $articleRepo->findPubliesPagines($page, 9);
        $total    = $articleRepo->countPublies();

        return $this->render('vitrine/accueil/news.html.twig', [
            'articles'   => $articles,
            'page'       => $page,
            'totalPages' => (int) ceil($total / 9),
        ]);
    }

    #[Route('/club', name: 'vitrine_club')]
    public function club(): Response
    {
        return $this->render('vitrine/accueil/club.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/equipes', name: 'vitrine_equipes')]
    public function equipes(): Response
    {
        return $this->render('vitrine/accueil/equipes.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/galerie', name: 'vitrine_galerie')]
    public function galerie(MediaRepository $mediaRepo): Response
    {
        return $this->render('vitrine/accueil/galerie.html.twig', [
            'medias' => $mediaRepo->findImages(48),
        ]);
    }

    #[Route('/calendrier', name: 'vitrine_calendrier')]
    public function calendrier(): Response
    {
        return $this->render('vitrine/accueil/calendrier.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/numerique', name: 'vitrine_numerique')]
    public function numerique(): Response
    {
        return $this->render('vitrine/accueil/numerique.html.twig', [
            'controller_name' => 'AccueilController',
        ]);
    }

    #[Route('/news/{slug}', name: 'vitrine_news_article')]
    public function article(string $slug, ArticleRepository $articleRepo): Response
    {
        $article = $articleRepo->findOneBy(['slug' => $slug, 'statut' => 'publie']);

        if (!$article) {
            throw $this->createNotFoundException('Article non trouvé.');
        }

        return $this->render('vitrine/accueil/article.html.twig', [
            'article' => $article,
        ]);
    }

    #[Route('/contact', name: 'vitrine_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $success = false;
        $errors  = [];

        if ($request->isMethod('POST')) {
            // Vérification token CSRF
            if (!$this->isCsrfTokenValid('contact_form', $request->request->get('_csrf_token'))) {
                $errors[] = 'Formulaire invalide. Veuillez réessayer.';
            } else {
                // Récupération et nettoyage des champs
                $nom       = trim(strip_tags($request->request->get('nom', '')));
                $prenom    = trim(strip_tags($request->request->get('prenom', '')));
                $email     = trim($request->request->get('email', ''));
                $telephone = trim(strip_tags($request->request->get('telephone', '')));
                $sujet     = trim(strip_tags($request->request->get('sujet', '')));
                $message   = trim(strip_tags($request->request->get('message', '')));

                // Validation
                if (empty($nom) || empty($prenom)) {
                    $errors[] = 'Le nom et le prénom sont obligatoires.';
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Adresse email invalide.';
                }
                if (empty($sujet)) {
                    $errors[] = 'Veuillez choisir un sujet.';
                }
                if (empty($message) || strlen($message) < 10) {
                    $errors[] = 'Le message doit faire au moins 10 caractères.';
                }

                if (empty($errors)) {
                    try {
                        $corps = '
                            <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
                                <div style="background:#063a55;padding:20px 30px;border-radius:8px 8px 0 0">
                                    <h2 style="color:#fff;margin:0">📬 Nouveau message — mabb.fr</h2>
                                </div>
                                <div style="background:#f8f9fa;padding:25px 30px;border:1px solid #dee2e6;border-top:none;border-radius:0 0 8px 8px">
                                    <table style="width:100%;border-collapse:collapse">
                                        <tr>
                                            <td style="padding:8px 0;font-weight:bold;width:120px;color:#063a55">De :</td>
                                            <td style="padding:8px 0">' . $prenom . ' ' . $nom . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:8px 0;font-weight:bold;color:#063a55">Email :</td>
                                            <td style="padding:8px 0"><a href="mailto:' . $email . '">' . $email . '</a></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:8px 0;font-weight:bold;color:#063a55">Téléphone :</td>
                                            <td style="padding:8px 0">' . ($telephone ?: 'Non renseigné') . '</td>
                                        </tr>
                                        <tr>
                                            <td style="padding:8px 0;font-weight:bold;color:#063a55">Sujet :</td>
                                            <td style="padding:8px 0">' . $sujet . '</td>
                                        </tr>
                                    </table>
                                    <hr style="margin:15px 0;border:none;border-top:1px solid #dee2e6">
                                    <p style="font-weight:bold;color:#063a55;margin-bottom:8px">Message :</p>
                                    <div style="background:#fff;padding:15px;border-radius:6px;border:1px solid #dee2e6">
                                        ' . nl2br(htmlspecialchars($message)) . '
                                    </div>
                                    <hr style="margin:15px 0;border:none;border-top:1px solid #dee2e6">
                                    <p style="color:#6c757d;font-size:.85rem;margin:0">
                                        Message envoyé depuis <strong>mabb.fr/contact</strong>
                                    </p>
                                </div>
                            </div>
                        ';

                        $emailMessage = (new Email())
                            ->from('noreply@mabb.fr')
                            ->to('contact@mabb.fr')          // boîte officielle du club
                            ->addTo('reseauxmabb@gmail.com') // Clavel — réseaux sociaux
                            ->replyTo($email)                // répondre directement à l'expéditeur
                            ->subject('[MABB Contact] ' . ucfirst($sujet) . ' — ' . $prenom . ' ' . $nom)
                            ->html($corps);

                        $mailer->send($emailMessage);
                        $success = true;
                    } catch (\Exception $e) {
                        $errors[] = 'Erreur lors de l\'envoi. Contactez-nous directement à contact@mabb.fr';
                    }
                }
            }
        }

        return $this->render('vitrine/accueil/contact.html.twig', [
            'success' => $success,
            'errors'  => $errors,
        ]);
    }

    #[Route('/equipes/3x3', name: 'vitrine_equipes_3x3')]
    public function equipes3x3(): Response
    {
        return $this->render('vitrine/accueil/equipes_3x3.html.twig');
    }
}
