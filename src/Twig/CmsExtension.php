<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Vitrine\BlocContenu;
use App\Repository\Vitrine\BlocContenuRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * CmsExtension — [CMS V2 05/07/2026]
 *
 * Fonctions Twig pour rendre la vitrine éditable BLOC PAR BLOC :
 *
 *   {{ cms('accueil.hero.titre', 'Le basket féminin à Amiens') }}
 *   {{ cms('accueil.hero.texte', 'Un long paragraphe…', 'long') }}
 *   <img src="{{ asset(cms_img('accueil.engage.photo', 'images/panierGonflable.jpeg')) }}">
 *
 * COMPORTEMENT :
 *   1. La valeur saisie par l'admin (/admin/contenus) est renvoyée si
 *      elle existe, sinon le DÉFAUT écrit dans le template.
 *   2. AUTO-ENREGISTREMENT : une clé inconnue est créée en base au premier
 *      rendu (avec son défaut) → elle apparaît dans le back-office sans
 *      aucune intervention développeur.
 *   3. BLINDÉ : toute erreur BDD (table absente avant migration, etc.)
 *      → on renvoie le défaut. La vitrine ne casse JAMAIS à cause du CMS.
 *
 * PERF : un seul findAll() par requête HTTP (cache mémoire local),
 * la table est petite (quelques dizaines de blocs).
 */
class CmsExtension extends AbstractExtension
{
    /** @var array<string, BlocContenu>|null cache par requête */
    private ?array $cache = null;

    public function __construct(
        private readonly BlocContenuRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cms', [$this, 'cms']),
            new TwigFunction('cms_img', [$this, 'cmsImg']),
        ];
    }

    /**
     * Contenu texte éditable. $type : 'texte' (court) ou 'long' (textarea).
     */
    public function cms(string $cle, string $defaut = '', string $type = BlocContenu::TYPE_TEXTE): string
    {
        return $this->resoudre($cle, $defaut, $type);
    }

    /**
     * Image éditable — renvoie un CHEMIN (relatif à public/) à passer à asset().
     * Les uploads admin vont dans uploads/cms/, les défauts pointent vers
     * les images existantes du template.
     */
    public function cmsImg(string $cle, string $defaut = ''): string
    {
        return $this->resoudre($cle, $defaut, BlocContenu::TYPE_IMAGE);
    }

    private function resoudre(string $cle, string $defaut, string $type): string
    {
        try {
            if ($this->cache === null) {
                $this->cache = $this->repo->toutIndexeParCle();
            }

            $bloc = $this->cache[$cle] ?? null;

            if ($bloc === null) {
                // Auto-enregistrement au premier rendu
                $bloc = (new BlocContenu())
                    ->setCle($cle)
                    ->setType($type)
                    ->setDefaut($defaut);
                $this->em->persist($bloc);
                $this->em->flush();
                $this->cache[$cle] = $bloc;
                return $defaut;
            }

            // Le défaut du template a évolué ? On garde la référence à jour
            // (sans toucher à la valeur saisie par l'admin).
            if ($bloc->getDefaut() !== $defaut) {
                $bloc->setDefaut($defaut);
                $this->em->flush();
            }

            return $bloc->getValeur() ?? $defaut;
        } catch (\Throwable $e) {
            // Table absente (migration pas encore jouée), DB down, doublon
            // concurrent… → la vitrine affiche le défaut, point.
            $this->logger->warning('CMS bloc irrésolu — défaut utilisé', [
                'cle' => $cle, 'erreur' => $e->getMessage(),
            ]);
            return $defaut;
        }
    }
}
