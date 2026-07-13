<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\FeedbackSeance;
use App\Entity\Sport\Seance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeedbackSeance>
 *
 * Ce repository ne sait pas qui a écrit quoi, et c'est voulu. Pour savoir si une
 * joueuse a déjà répondu, ou combien de fois, passer par
 * FeedbackParticipationRepository.
 *
 * Ne JAMAIS ajouter ici de méthode qui filtre par joueur : elle ne verrait que les
 * retours signés, donnerait des résultats faux, et rouvrirait la porte qu'on vient
 * de fermer.
 */
class FeedbackSeanceRepository extends ServiceEntityRepository
{
    /**
     * En dessous de ce nombre de réponses, le coach ne voit rien.
     *
     * Ce n'est pas une pudeur d'affichage, c'est de l'arithmétique : sur une séance
     * où une seule joueuse a répondu, montrer "une note de 1/5" au coach revient à
     * lui désigner l'autrice, puisqu'il sait qui était là. Trois réponses, c'est le
     * minimum pour qu'un retour se fonde dans les autres.
     */
    public const SEUIL_AFFICHAGE = 3;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeedbackSeance::class);
    }

    /**
     * Ce que le coach a le droit de voir pour une séance.
     *
     * Sous le seuil, on renvoie le compteur et rien d'autre : ni note, ni moyenne,
     * ni commentaire. Le coach sait que des retours arrivent, sans pouvoir les lire.
     *
     * @return array{
     *   sous_le_seuil: bool,
     *   seuil: int,
     *   nb_reponses: int,
     *   moyenne: float|null,
     *   distribution: array<int,int>,
     *   commentaires: list<array{note:int, commentaire:string, signe_par:string|null}>
     * }
     */
    public function synthesePourSeance(Seance $seance): array
    {
        /** @var FeedbackSeance[] $feedbacks */
        $feedbacks = $this->createQueryBuilder('f')
            ->leftJoin('f.joueur', 'j')->addSelect('j')
            ->where('f.seance = :s')
            ->setParameter('s', $seance)
            ->getQuery()
            ->getResult();

        $nb = \count($feedbacks);

        if ($nb < self::SEUIL_AFFICHAGE) {
            return [
                'sous_le_seuil' => true,
                'seuil'         => self::SEUIL_AFFICHAGE,
                'nb_reponses'   => $nb,
                'moyenne'       => null,
                'distribution'  => [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                'commentaires'  => [],
            ];
        }

        $distribution = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $somme        = 0;
        $commentaires = [];

        foreach ($feedbacks as $f) {
            $note = $f->getNote();
            $distribution[$note]++;
            $somme += $note;

            $texte = trim((string) $f->getCommentaire());
            if ($texte !== '') {
                $commentaires[] = [
                    'note'       => $note,
                    'commentaire' => $texte,
                    // Renseigné uniquement si la joueuse a choisi de signer.
                    // Sur un retour anonyme, getJoueur() vaut NULL en base : il n'y
                    // a rien à cacher, il n'y a rien.
                    'signe_par'  => $f->isAnonyme() ? null : $f->getJoueur()?->getPrenom(),
                ];
            }
        }

        // On brasse : l'ordre d'affichage ne doit pas refléter l'ordre d'arrivée,
        // sinon "le premier commentaire" = "la première qui a répondu".
        shuffle($commentaires);

        return [
            'sous_le_seuil' => false,
            'seuil'         => self::SEUIL_AFFICHAGE,
            'nb_reponses'   => $nb,
            'moyenne'       => round($somme / $nb, 2),
            'distribution'  => $distribution,
            'commentaires'  => $commentaires,
        ];
    }

    /**
     * Nombre de retours par séance, pour la liste des séances d'une équipe.
     *
     * @return array<int,int> [seance_id => nb_reponses]
     */
    public function compterParSeancePourEquipe(Equipe $equipe): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.seance) AS seance_id, COUNT(f.id) AS nb')
            ->join('f.seance', 's')
            ->where('s.equipe = :e')
            ->setParameter('e', $equipe)
            ->groupBy('f.seance')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['seance_id']] = (int) $r['nb'];
        }

        return $map;
    }
}
