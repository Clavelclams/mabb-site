<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Sport\Equipe;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\JoueurEquipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JoueurEquipe>
 */
class JoueurEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JoueurEquipe::class);
    }

    /**
     * Toutes les affectations ACTIVES d'une joueuse pour une saison.
     *
     * Utilisé par le profil PIRB pour lister "Mes équipes" et pour récupérer
     * tous les matchs concernés dans le bilan saison.
     *
     * @return JoueurEquipe[]
     */
    public function affectationsActivesParSaison(Joueur $joueur, string $saison): array
    {
        return $this->createQueryBuilder('je')
            ->andWhere('je.joueur = :joueur')
            ->andWhere('je.saison = :saison')
            ->andWhere('je.actif = true')
            ->setParameter('joueur', $joueur)
            ->setParameter('saison', $saison)
            ->orderBy('je.type', 'ASC') // 'principale' avant 'surclassement' alphabétiquement
            ->getQuery()
            ->getResult();
    }

    /**
     * Toutes les équipes (objets Equipe) où une joueuse est affectée actif sur une saison.
     * Plus pratique que affectationsActivesParSaison() quand on veut juste les Equipe.
     *
     * @return Equipe[]
     */
    public function equipesActivesParSaison(Joueur $joueur, string $saison): array
    {
        return array_map(
            static fn(JoueurEquipe $je) => $je->getEquipe(),
            $this->affectationsActivesParSaison($joueur, $saison)
        );
    }

    /**
     * Toutes les joueuses ACTIVES affectées à une équipe pour une saison
     * — inclut principales + surclassements.
     *
     * Utilisé pour le roster complet d'une équipe (page Manager équipe,
     * convocations, etc.).
     *
     * @return JoueurEquipe[]
     */
    public function joueusesParEquipeSaison(Equipe $equipe, string $saison): array
    {
        return $this->createQueryBuilder('je')
            ->andWhere('je.equipe = :equipe')
            ->andWhere('je.saison = :saison')
            ->andWhere('je.actif = true')
            ->setParameter('equipe', $equipe)
            ->setParameter('saison', $saison)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve l'affectation principale d'une joueuse pour une saison
     * (devrait retourner exactement 1 résultat par invariant métier).
     */
    public function affectationPrincipale(Joueur $joueur, string $saison): ?JoueurEquipe
    {
        return $this->createQueryBuilder('je')
            ->andWhere('je.joueur = :joueur')
            ->andWhere('je.saison = :saison')
            ->andWhere('je.type = :type')
            ->setParameter('joueur', $joueur)
            ->setParameter('saison', $saison)
            ->setParameter('type', JoueurEquipe::TYPE_PRINCIPALE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
