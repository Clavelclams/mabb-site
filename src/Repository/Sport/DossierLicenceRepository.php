<?php

declare(strict_types=1);

namespace App\Repository\Sport;

use App\Entity\Core\Club;
use App\Entity\Sport\DossierLicence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DossierLicence>
 */
class DossierLicenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DossierLicence::class);
    }

    /**
     * Liste filtrée pour le tableau du secrétariat.
     *
     * @return DossierLicence[]
     */
    public function rechercher(Club $club, string $saison, ?string $site = null, ?string $categorie = null, ?string $statut = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.club = :club')->setParameter('club', $club)
            ->andWhere('d.saison = :saison')->setParameter('saison', $saison)
            ->orderBy('d.site', 'ASC')
            ->addOrderBy('d.categorie', 'ASC')
            ->addOrderBy('d.nomComplet', 'ASC');

        if ($site !== null && $site !== '') {
            $qb->andWhere('d.site = :site')->setParameter('site', $site);
        }
        if ($categorie !== null && $categorie !== '') {
            $qb->andWhere('d.categorie = :cat')->setParameter('cat', $categorie);
        }
        if ($statut !== null && $statut !== '' && in_array($statut, DossierLicence::PAIEMENT_STATUTS, true)) {
            $qb->andWhere('d.paiementStatut = :st')->setParameter('st', $statut);
        }

        return $qb->getQuery()->getResult();
    }

    /** Sites distincts d'une saison (pour les filtres). @return string[] */
    public function sites(Club $club, string $saison): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.site')
            ->andWhere('d.club = :club')->setParameter('club', $club)
            ->andWhere('d.saison = :saison')->setParameter('saison', $saison)
            ->andWhere('d.site IS NOT NULL')
            ->orderBy('d.site', 'ASC')
            ->getQuery()->getScalarResult();
        return array_column($rows, 'site');
    }

    /** Catégories distinctes d'une saison. @return string[] */
    public function categories(Club $club, string $saison): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.categorie')
            ->andWhere('d.club = :club')->setParameter('club', $club)
            ->andWhere('d.saison = :saison')->setParameter('saison', $saison)
            ->andWhere('d.categorie IS NOT NULL')
            ->orderBy('d.categorie', 'ASC')
            ->getQuery()->getScalarResult();
        return array_column($rows, 'categorie');
    }

    /**
     * Compteurs du dashboard : total, payés, à relancer, par site.
     *
     * @return array{total:int, payes:int, a_relancer:int, jamais_relances:int, par_site:array<string,int>}
     */
    public function statsDashboard(Club $club, string $saison): array
    {
        /** @var DossierLicence[] $dossiers */
        $dossiers = $this->findBy(['club' => $club, 'saison' => $saison]);

        $stats = ['total' => count($dossiers), 'payes' => 0, 'a_relancer' => 0, 'jamais_relances' => 0, 'par_site' => []];
        foreach ($dossiers as $d) {
            if ($d->getPaiementStatut() === DossierLicence::PAIEMENT_PAYE
                || $d->getPaiementStatut() === DossierLicence::PAIEMENT_EXONERE) {
                $stats['payes']++;
            }
            if ($d->estArelancer()) {
                $stats['a_relancer']++;
                if ($d->getRelanceLe() === null) {
                    $stats['jamais_relances']++;
                }
            }
            $site = $d->getSite() ?? 'Sans site';
            $stats['par_site'][$site] = ($stats['par_site'][$site] ?? 0) + 1;
        }
        ksort($stats['par_site']);
        return $stats;
    }

    /** Dossier existant pour l'upsert d'import (n° licence prioritaire, sinon nom+saison). */
    public function trouverPourImport(Club $club, string $saison, ?string $numeroLicence, string $nomComplet): ?DossierLicence
    {
        if ($numeroLicence !== null && $numeroLicence !== '') {
            $parNumero = $this->findOneBy(['club' => $club, 'saison' => $saison, 'numeroLicence' => $numeroLicence]);
            if ($parNumero !== null) {
                return $parNumero;
            }
        }
        return $this->createQueryBuilder('d')
            ->andWhere('d.club = :club')->setParameter('club', $club)
            ->andWhere('d.saison = :saison')->setParameter('saison', $saison)
            ->andWhere('LOWER(d.nomComplet) = :nom')->setParameter('nom', mb_strtolower(trim($nomComplet)))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
