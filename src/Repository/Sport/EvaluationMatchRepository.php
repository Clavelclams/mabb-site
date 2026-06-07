<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\EvaluationMatch;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\Rencontre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository EvaluationMatch — accès BDD aux évaluations de match.
 *
 * POURQUOI UN REPOSITORY ET PAS DES QUERIES DANS LE CONTRÔLEUR :
 *   Symfony 7 best practice : la logique d'accès BDD est centralisée ici.
 *   Si la formule "saison" change, on ne corrige qu'à un endroit. Si on
 *   veut tester unitairement, on peut mocker le Repository complet.
 *
 * CALCUL DE LA SAISON :
 *   Une saison MABB va de septembre N à août N+1.
 *   Exemple : saison "2025-2026" = rencontres entre 2025-09-01 et 2026-08-31.
 *   La logique est dans saisonBounds() pour éviter de la répéter.
 *
 * @extends ServiceEntityRepository<EvaluationMatch>
 */
class EvaluationMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationMatch::class);
    }

    /**
     * Retrouve l'éval d'un joueur sur une rencontre, ou null si pas encore saisie.
     *
     * Cas d'usage typique : sur la page de saisie, on charge les évals existantes
     * pour pré-remplir le formulaire (sinon création d'une nouvelle ligne).
     */
    public function findOneByJoueurAndRencontre(Joueur $joueur, Rencontre $rencontre): ?EvaluationMatch
    {
        return $this->createQueryBuilder('e')
            ->where('e.joueur = :joueur')
            ->andWhere('e.rencontre = :rencontre')
            ->setParameter('joueur', $joueur)
            ->setParameter('rencontre', $rencontre)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Toutes les évals d'une saison pour un joueur, ordonnées par date décroissante
     * (dernière rencontre en premier).
     *
     * @return EvaluationMatch[]
     */
    public function evaluationsSaison(Joueur $joueur, string $saison): array
    {
        [$dateDebut, $dateFin] = self::saisonBounds($saison);

        return $this->createQueryBuilder('e')
            ->join('e.rencontre', 'r')
            ->where('e.joueur = :joueur')
            ->andWhere('r.date BETWEEN :debut AND :fin')
            ->setParameter('joueur', $joueur)
            ->setParameter('debut', $dateDebut)
            ->setParameter('fin', $dateFin)
            ->orderBy('r.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Les N dernières évals d'un joueur (toutes saisons confondues).
     *
     * Utilisée sur la fiche joueuse pour afficher un tableau "5 derniers matchs".
     *
     * @return EvaluationMatch[]
     */
    public function evaluationsRecentes(Joueur $joueur, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.rencontre', 'r')
            ->where('e.joueur = :joueur')
            ->setParameter('joueur', $joueur)
            ->orderBy('r.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les évals d'une rencontre (toutes joueuses confondues).
     *
     * Utilisée sur la page de saisie : on récupère toutes les évals existantes
     * pour pré-remplir le formulaire en une seule requête (pas N+1).
     *
     * @return EvaluationMatch[]
     */
    public function evaluationsRencontre(Rencontre $rencontre): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.rencontre = :rencontre')
            ->setParameter('rencontre', $rencontre)
            ->getQuery()
            ->getResult();
    }

    /**
     * Convertit une saison "2025-2026" en bornes de date [2025-09-01, 2026-08-31].
     *
     * Pourquoi statique : pas de dépendance à Doctrine, testable unitairement
     * sans bootstrap Symfony.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function saisonBounds(string $saison): array
    {
        // Format attendu : "AAAA-AAAA" (ex: "2025-2026")
        if (!preg_match('/^(\d{4})-(\d{4})$/', $saison, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Format de saison invalide : "%s". Attendu : "AAAA-AAAA" (ex: "2025-2026").',
                $saison
            ));
        }
        $anneeDebut = (int) $matches[1];
        $anneeFin   = (int) $matches[2];

        // Sanity check : les deux années doivent être consécutives
        if ($anneeFin !== $anneeDebut + 1) {
            throw new \InvalidArgumentException(sprintf(
                'Années de saison non consécutives : "%s".',
                $saison
            ));
        }

        $debut = new \DateTimeImmutable($anneeDebut . '-09-01 00:00:00');
        $fin   = new \DateTimeImmutable($anneeFin   . '-08-31 23:59:59');

        return [$debut, $fin];
    }
}
