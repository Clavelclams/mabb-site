<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Core\User;
use App\Entity\Sport\OtmInterdiction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OtmInterdiction>
 */
class OtmInterdictionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OtmInterdiction::class);
    }

    /**
     * Les codes de postes INTERDITS à cette personne dans ce club.
     *
     * @return list<string> ex. ['ARBITRE_1', 'ARBITRE_2']
     */
    public function rolesInterditsPour(Club $club, User $user): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('i.role')
            ->andWhere('i.club = :club')->setParameter('club', $club)
            ->andWhere('i.user = :user')->setParameter('user', $user)
            ->getQuery()->getScalarResult();

        return array_map(static fn (array $r): string => (string) $r['role'], $rows);
    }

    /** Cette personne a-t-elle interdiction de tenir ce poste ? */
    public function estInterdit(Club $club, User $user, string $role): bool
    {
        return $this->count(['club' => $club, 'user' => $user, 'role' => $role]) > 0;
    }

    /**
     * Toutes les interdictions du club, indexées par id d'utilisateur.
     *
     * @return array<int, list<string>> [userId => ['ARBITRE_1', ...]]
     */
    public function parUtilisateurPourClub(Club $club): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.user) AS uid', 'i.role')
            ->andWhere('i.club = :club')->setParameter('club', $club)
            ->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['uid']][] = (string) $r['role'];
        }

        return $out;
    }

    /**
     * Remplace TOUTES les interdictions d'une personne par la liste fournie
     * (utilisé par les cases à cocher de la fiche staff). Ne flush pas.
     *
     * @param list<string> $roles codes de postes à interdire
     */
    public function remplacerPour(Club $club, User $user, array $roles): void
    {
        $em = $this->getEntityManager();

        foreach ($this->findBy(['club' => $club, 'user' => $user]) as $existante) {
            $em->remove($existante);
        }

        foreach (array_unique($roles) as $role) {
            $em->persist(
                (new OtmInterdiction())->setClub($club)->setUser($user)->setRole($role)
            );
        }
    }
}
