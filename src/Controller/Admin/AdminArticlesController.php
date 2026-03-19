<?php

namespace App\Controller\Admin;

use App\Entity\Vitrine\Article;
use App\Repository\Vitrine\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/articles')]
class AdminArticlesController extends AbstractController
{
    #[Route('', name: 'admin_articles_list')]
    public function index(ArticleRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        return $this->render('admin/articles/index.html.twig', [
            'articles' => $repo->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/nouveau', name: 'admin_articles_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('article_form', $request->request->get('_csrf_token'))) {
                $errors[] = 'Token CSRF invalide.';
            } else {
                $article = new Article();
                $article->setTitre(trim($request->request->get('titre', '')));
                $article->setContenu($request->request->get('contenu', ''));
                $article->setStatut($request->request->get('statut', Article::STATUT_BROUILLON));
                $article->setAuteur($this->getUser());

                if ($article->getStatut() === Article::STATUT_PUBLIE) {
                    $article->setPublishedAt(new \DateTimeImmutable());
                }

                // Upload image
                $imageFile = $request->files->get('image');
                if ($imageFile) {
                    $newFilename = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME))
                        . '-' . uniqid() . '.' . $imageFile->guessExtension();
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/articles',
                        $newFilename
                    );
                    $article->setImagePath($newFilename);
                }

                if (empty($article->getTitre())) {
                    $errors[] = 'Le titre est obligatoire.';
                }

                if (empty($errors)) {
                    $em->persist($article);
                    $em->flush();
                    $this->addFlash('success', 'Article créé ✅');

                    return $this->redirectToRoute('admin_articles_list');
                }
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => null,
            'errors'  => $errors,
        ]);
    }

    #[Route('/{id}/modifier', name: 'admin_articles_edit', methods: ['GET', 'POST'])]
    public function edit(Article $article, Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('article_form', $request->request->get('_csrf_token'))) {
                $errors[] = 'Token CSRF invalide.';
            } else {
                $article->setTitre(trim($request->request->get('titre', '')));
                $article->setContenu($request->request->get('contenu', ''));
                $newStatut = $request->request->get('statut', Article::STATUT_BROUILLON);

                if ($newStatut === Article::STATUT_PUBLIE && !$article->getPublishedAt()) {
                    $article->setPublishedAt(new \DateTimeImmutable());
                }
                $article->setStatut($newStatut);

                $imageFile = $request->files->get('image');
                if ($imageFile) {
                    $newFilename = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME))
                        . '-' . uniqid() . '.' . $imageFile->guessExtension();
                    $imageFile->move(
                        $this->getParameter('kernel.project_dir') . '/public/uploads/articles',
                        $newFilename
                    );
                    $article->setImagePath($newFilename);
                }

                if (empty($article->getTitre())) {
                    $errors[] = 'Le titre est obligatoire.';
                }

                if (empty($errors)) {
                    $em->flush();
                    $this->addFlash('success', 'Article mis à jour ✅');

                    return $this->redirectToRoute('admin_articles_list');
                }
            }
        }

        return $this->render('admin/articles/form.html.twig', [
            'article' => $article,
            'errors'  => $errors,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'admin_articles_delete', methods: ['POST'])]
    public function delete(Article $article, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        if ($this->isCsrfTokenValid('delete_article_' . $article->getId(), $request->request->get('_csrf_token'))) {
            $em->remove($article);
            $em->flush();
            $this->addFlash('success', 'Article supprimé.');
        }

        return $this->redirectToRoute('admin_articles_list');
    }
}
