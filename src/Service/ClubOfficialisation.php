<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Core\Club;
use App\Entity\Core\OrganismeFfbb;
use App\Repository\Core\OrganismeFfbbRepository;

/**
 * ClubOfficialisation — pose le statut « officiel » d'un club selon le
 * référentiel FFBB (table organisme_ffbb).
 *
 * Règle produit : un club est OFFICIEL si son numeroFfbb correspond à un
 * organisme officiel importé depuis l'annuaire FFBB. Sinon il reste
 * NON-OFFICIEL (mêmes fonctionnalités, juste pas le badge ni la protection
 * anti-doublon). Appelé à la création et à chaque édition du n° FFBB.
 */
class ClubOfficialisation
{
    public function __construct(
        private readonly OrganismeFfbbRepository $organismeRepo,
    ) {}

    /**
     * Recalcule et pose isOfficiel sur le club d'après son numeroFfbb.
     * @return bool true si le club est (devient) officiel
     */
    public function rafraichir(Club $club): bool
    {
        $numero   = $club->getNumeroFfbb();
        $officiel = $numero !== null && $this->organismeRepo->estOfficiel($numero);
        $club->setIsOfficiel($officiel);

        return $officiel;
    }

    /**
     * L'organisme FFBB correspondant au n° du club (pour pré-remplir/vérifier
     * le nom officiel côté formulaire), ou null si non trouvé.
     */
    public function organismePour(Club $club): ?OrganismeFfbb
    {
        $numero = $club->getNumeroFfbb();

        return $numero !== null ? $this->organismeRepo->findOneByNumero($numero) : null;
    }
}
