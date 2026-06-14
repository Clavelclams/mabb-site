<?php

declare(strict_types=1);

namespace App\Service\SectionSportive;

use App\Entity\Sport\BulletinScolaire;
use App\Entity\Sport\Joueur;
use App\Entity\Sport\NoteScolaire;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * [B33 12/06/2026] Import de bulletins scolaires.
 *
 * V1 : import manuel via UI (parent/staff upload PDF/image + saisie notes)
 * V2 : import via JSON externe quand le collège César Franck ouvre une API
 *       (Pronote / EcoleDirecte / autre).
 *
 * Le service expose 2 méthodes :
 *   - createManuelle(...) pour la saisie UI
 *   - importFromJson(...) pour l'API externe (squelette V2)
 *
 * Le format JSON attendu (à finaliser quand on aura accès à l'API) :
 * {
 *   "joueur_licence": "VT070637",
 *   "annee_scolaire": "2026-2027",
 *   "trimestre": "T1",
 *   "moyenne_generale": 14.2,
 *   "appreciation_globale": "Très bon trimestre...",
 *   "matieres": [
 *     {"matiere": "Maths", "moyenne": 15.5, "coef": 4, "appreciation": "Excellent", "moy_classe": 12.3},
 *     ...
 *   ]
 * }
 */
class BulletinImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Création manuelle d'un bulletin (workflow UI parent/staff).
     */
    public function createManuelle(
        Joueur $joueur,
        string $anneeScolaire,
        string $trimestre,
        ?string $filePath,
        ?float $moyenneGenerale,
        ?string $appreciationGlobale,
        array $notes = [],
        ?\App\Entity\Core\User $uploadedBy = null,
    ): BulletinScolaire {
        $bulletin = new BulletinScolaire();
        $bulletin->setJoueur($joueur);
        $bulletin->setAnneeScolaire($anneeScolaire);
        $bulletin->setTrimestre($trimestre);
        $bulletin->setFilePath($filePath);
        $bulletin->setMoyenneGenerale($moyenneGenerale);
        $bulletin->setAppreciationGlobale($appreciationGlobale);
        $bulletin->setUploadedBy($uploadedBy);
        $bulletin->setSource(BulletinScolaire::SOURCE_MANUEL);

        foreach ($notes as $n) {
            $note = new NoteScolaire();
            $note->setMatiere($n['matiere'] ?? '');
            $note->setMoyenne(isset($n['moyenne']) ? (float) $n['moyenne'] : null);
            $note->setCoefficient((int) ($n['coef'] ?? 1));
            $note->setAppreciation($n['appreciation'] ?? null);
            $note->setMoyenneClasse(isset($n['moy_classe']) ? (float) $n['moy_classe'] : null);
            $bulletin->addNote($note);
        }

        $this->em->persist($bulletin);
        $this->em->flush();
        return $bulletin;
    }

    /**
     * Import depuis JSON externe (API collège — V2 squelette).
     *
     * @param array $data Format documenté en doc-block de classe
     * @param Joueur $joueur La joueuse cible (résolue par licence côté caller)
     */
    public function importFromJson(array $data, Joueur $joueur, string $source = BulletinScolaire::SOURCE_API_PRONOTE): ?BulletinScolaire
    {
        if (!isset($data['annee_scolaire'], $data['trimestre'])) {
            $this->logger->warning('JSON bulletin invalide : champs requis manquants', ['data' => $data]);
            return null;
        }

        $bulletin = new BulletinScolaire();
        $bulletin->setJoueur($joueur);
        $bulletin->setAnneeScolaire($data['annee_scolaire']);
        $bulletin->setTrimestre($data['trimestre']);
        $bulletin->setMoyenneGenerale(isset($data['moyenne_generale']) ? (float) $data['moyenne_generale'] : null);
        $bulletin->setAppreciationGlobale($data['appreciation_globale'] ?? null);
        $bulletin->setSource($source);

        foreach (($data['matieres'] ?? []) as $n) {
            $note = new NoteScolaire();
            $note->setMatiere($n['matiere'] ?? '');
            $note->setMoyenne(isset($n['moyenne']) ? (float) $n['moyenne'] : null);
            $note->setCoefficient((int) ($n['coef'] ?? 1));
            $note->setAppreciation($n['appreciation'] ?? null);
            $note->setMoyenneClasse(isset($n['moy_classe']) ? (float) $n['moy_classe'] : null);
            $bulletin->addNote($note);
        }

        $this->em->persist($bulletin);
        $this->em->flush();

        $this->logger->info('Bulletin importé depuis API', [
            'joueur_id' => $joueur->getId(),
            'source'    => $source,
            'bulletin_id' => $bulletin->getId(),
        ]);

        return $bulletin;
    }
}
