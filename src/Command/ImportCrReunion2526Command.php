<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Core\UserClubRole;
use App\Entity\Sport\Reunion;
use App\Entity\Sport\ReunionConvocation;
use App\Repository\Core\ClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe le compte rendu de la réunion du 25 juin 2026 (Bilan & Restructuration 2026/27)
 * directement depuis le code — pas besoin de saisir à la main dans Manager.
 *
 * Usage :
 *   php bin/console app:import-cr-25062026
 *   php bin/console app:import-cr-25062026 --club=mabb   # si multi-clubs
 *
 * Idempotent : si une Réunion du même titre et de la même date existe déjà,
 * la commande l'ignore (pas de doublon).
 */
#[AsCommand(
    name: 'app:import-cr-25062026',
    description: 'Importe le CR de la réunion Bilan & Restructuration 2026/27 (25/06/2026) dans Manager.',
)]
class ImportCrReunion2526Command extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClubRepository $clubRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('club', null, InputOption::VALUE_OPTIONAL, 'Slug ou ID du club cible (défaut : premier club trouvé)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans écrire en BDD')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');

        $io->title('Import CR Réunion — 25 juin 2026 — Bilan & Restructuration MABB 2026/27');

        // ── Trouver le club ───────────────────────────────────────────────────
        $clubOption = $input->getOption('club');
        if ($clubOption) {
            $club = is_numeric($clubOption)
                ? $this->clubRepository->find((int) $clubOption)
                : $this->clubRepository->findOneBy(['slug' => $clubOption]);
        } else {
            $clubs = $this->clubRepository->findAll();
            $club  = $clubs[0] ?? null;
        }

        if ($club === null) {
            $io->error('Aucun club trouvé. Créer un club d\'abord ou passer --club=<slug>.');
            return Command::FAILURE;
        }
        $io->text(sprintf('Club cible : <info>%s</info>', $club->getNom()));

        // ── Idempotence : vérifier si la réunion existe déjà ─────────────────
        $titre = 'Bilan & Restructuration 2026/27';
        $date  = new \DateTimeImmutable('2026-06-25 19:00:00');

        $existante = $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reunion::class, 'r')
            ->where('r.club = :club')
            ->andWhere('r.titre = :titre')
            ->andWhere('r.date = :date')
            ->setParameter('club', $club)
            ->setParameter('titre', $titre)
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existante !== null) {
            $io->success(sprintf('Réunion "%s" déjà présente (ID %d) — rien à faire.', $titre, $existante->getId()));
            return Command::SUCCESS;
        }

        // ── Construire la Réunion ─────────────────────────────────────────────
        $reunion = new Reunion();
        $reunion->setClub($club);
        $reunion->setTitre($titre);
        $reunion->setType(Reunion::TYPE_AUTRE);
        $reunion->setStatut(Reunion::STATUT_TENUE);
        $reunion->setDate($date);
        $reunion->setLieu('Gymnase MABB — Étouvie');
        $reunion->setOrdreDuJour(
            "1. Bilan financier 2025/26\n" .
            "2. Ressources humaines — situations en cours\n" .
            "3. Événements & animations été 2026\n" .
            "4. Organisation sportive & licences 2026/27\n" .
            "5. Plannings d'entraînement 2026/27\n" .
            "6. Plan d'actions — décisions & suivi"
        );
        $reunion->setVisiblePourTous(false); // Confidentiel usage interne

        // ── PV / Compte rendu complet ─────────────────────────────────────────
        $pv = <<<END_PV
COMPTE RENDU — Réunion du 25 juin 2026
Bilan & Restructuration 2026/27
CONFIDENTIEL — Usage interne MABB uniquement

PRÉSENTS : Will (Président), Moussa, Clavel, Lenny, Hugo + staff
Rédacteur : MABB — Responsable Numérique

═══════════════════════════════════════════════════════════
01 — BILAN FINANCIER
═══════════════════════════════════════════════════════════

SITUATION CRITIQUE : déficit global estimé à ~109 000 € / budget prévisionnel.
Réduction drastique des dépenses obligatoire.
Objectif : atteindre le début de saison 2026/27 sans rupture de trésorerie.

Détail des déficits :
- Budget fonctionnement général : -50 000 € (baisse subventions depuis février)
- Cité Éducative : -10 000 € (abandon dispositif par Ville d'Amiens)
- Projet Thuillier (sport-études) : -20 000 € (non retenu Cité Éducative)
- Aides collèges (J.-M. Laurent + Amiens Nord) : -20 000 € (-10 000€/collège)
- Enveloppe été (3 000 € accordés / 12 000 € demandés) : -9 000 €
TOTAL : ~-109 000 €

État de trésorerie :
- Compte courant : ~21 000 € (habituellement ~100 000 € à cette période)
- Dépenses prévues ce mois : -21 000 € (salaires + charges)
- Solde estimé début juillet : ~10 000 € — TRÈS TENDU

MESURES IMMÉDIATES :
✓ Serrer la ceinture sur les dépenses
✓ Suspendre toutes les alternances
✓ Rechercher nouveaux financements (sponsors, loto, réderie, dîner dansant)
✓ Aucune licence validée sans paiement préalable

═══════════════════════════════════════════════════════════
02 — RESSOURCES HUMAINES
═══════════════════════════════════════════════════════════

Tony : CEFA non agréé → faux contrat. Procédure judiciaire. Récupération 6 000 € d'aide EN COURS.
David (hors MABB) : non retenu stage CIC U12. Poste clos.
Max : standby — contrat reprise envisagée fin 2026/2027.
Leny / Larissa : contrats arrivant à terme naturellement. Pas de renouvellement.
Neil : maintenu (contrat signé avant annonce des coupes).
Services Civiques (×7) : appel à candidatures réel. À pourvoir dès septembre.
  Profils attendus : disponibles, fiables, ponctuels.
  Missions : table de marque, gestion sportive, animations centre/camp.
  Pas de passe-droit.

ALTERNANCES : suspension totale des nouveaux recrutements.
Reprise envisageable fin 2026/début 2027 selon redressement financier.

═══════════════════════════════════════════════════════════
03 — ÉVÉNEMENTS ÉTÉ 2026
═══════════════════════════════════════════════════════════

27/06 — Structures gonflables, Corbie. CONFIRMÉ — Staff été.
02-03/07 — Animations + terrain double, Le Touquet. CONFIRMÉ (1 200+2 500 pers.)
          Responsables : Moussa, Nayl, Larissa + 1 fille
05/07 — Sortie mer joueuses. EN COURS (~15 réponses Moussa)
06/07 au 30/08 — Animations quartiers 18h–21h. PLANIFIÉ
          Amiens Sud / Nord / Étouvie par rotation
22/07 — Animation structures gonflables, Formahon. CONFIRMÉ
23/08 — Animation 3×3, Saint-Quentin. CONFIRMÉ (Will + équipe)
29-30/08 — Tournoi féminin (sam) + Tournoi darons (dim). À RÉSERVER gymnase MABB.

Planning animations quartiers (6 juillet – 30 août) :
Amiens Sud  S1: Filles d'Hastebecque | S2: Pierre Rollin | S3: Tour du Marais | S4: RCA
Amiens Nord S1: Pigeonnier | S2: Marivaux | S3: Phaffais (jusqu'au 27/07) | S4: Rotation
Étouvie     S1: Moirou | S2: Verlin | S3: Verlin + relance août | S4: Verlin

NOTE : Moussa récupère le camion du comité chez David mardi matin.
Plein + Karcher avant restitution à Julino le samedi.
Les Poussines ne sont PAS mobilisées sur les animations quartiers.

═══════════════════════════════════════════════════════════
04 — ORGANISATION SPORTIVE & LICENCES 2026/27
═══════════════════════════════════════════════════════════

Tarifs licences 2026/27 (+10 € sur toutes catégories FFBB) :
- Micro U6-U8 : 60 €
- Cadette : 130 €
- Senior/Adulte : 120 €
Règle absolue : aucune licence validée sans paiement préalable.

Championnats 2026/27 :
- U15 1 & 2 : Régional (qualifiées d'office)
- U18 : Régional (qualifié d'office)
- U13 : Barrage Régional (1er weekend septembre)
- U18 B / Cafard : option 3×3 MABB ligue hebdo (hors championnat)

Répartition coachs/staff :
- Senior : Clavel
- U18 A : Ugo (niveau région)
- U18 B / Cafard : Neil / Albert (équipe loisir)
- U15 A : Willy
- U15 B : Clavel
- U13 HDF (Région) : Moussa (Thomas GORJETTI aide mercredi)
- U13 B : Leny (Thomas GORJETTI aide jeudi)
- U13 C (optionnel) : Neil (à confirmer selon effectif)
- U11 A : Romy
- U11 B (surclassé) : Albert (surclassement à cocher FFBB)
- EMB Sud : Clavel (U15 sud — Maissa Amirat, Aïssatou...)
- EMB Ouest : Romy + Leny
- EMB Nord : Moussa + ? (binôme à compléter)
- U9 Nord : Staff Moussa | U9 Ouest : Staff Romy

═══════════════════════════════════════════════════════════
05 — PLANNINGS D'ENTRAÎNEMENT 2026/27
═══════════════════════════════════════════════════════════

→ Voir commande : php bin/console app:seed-plannings-2026-27
   (les créneaux récurrents sont créés automatiquement dans Manager)

ÉTOUVIE :
Lundi    18:00-19:30 CEC | 19:30-21:00 U13 Région | 21:00-22:30 Seniors Filles
Mardi    18:00-19:30 U13B | 19:30-21:00 U15 R2 | 21:00-22:30 Loisirs
Mercredi 15:00-16:00 Micro | 16:00-17:00 Baby | 17:00-18:00 Mini
         18:00-19:30 U15 R1 | 19:30-21:00 U18 R1 | 21:00-22:30 3×3
Jeudi    18:00-19:30 U13B | 19:30-21:00 U15 R2 | 21:00-22:30 Loisirs
Vendredi 18:00-19:30 U15 A | 19:30-21:00 U18 Région | 21:00-22:30 Seniors

NORD :
Mardi    18:30-20:00 U11/U13
Mercredi 16:00-17:00 Baby | 17:00-18:00 Mini | 18:00-19:15 Poussine
         19:15-20:45 U13 HDF | 20:45-22:20 ANBB
Vendredi 19:30-21:00 U15/U18

SUD :
Samedi   09:30-11:00 U7/U8/U9 | 11:00-12:00 U11
(planning weekday à compléter)

AJUSTEMENTS PROPOSÉS (en cours de validation) :
- Seniors pourraient passer le mercredi soir à Étouvie
- 3×3 déplacé au vendredi
- Créneau Seniors ajouté au Nord le vendredi soir
- Lundi soir Étouvie → créneau libre technique individuelle

═══════════════════════════════════════════════════════════
06 — PLAN D'ACTIONS
═══════════════════════════════════════════════════════════

URGENT :
□ Moussa : récupérer camion comité mardi matin + plein + Karcher
□ Moussa + Coachs : finaliser liste participants sortie mer (mercredi)
□ Coachs : donner papiers sortie mer aux familles (vendredi)
□ Will : réserver gymnase weekends 29-30/08 (aujourd'hui)

HAUT :
□ Tous coachs : listing officiel effectifs par catégorie (fin de semaine)
□ Coach U15 : envoyer programme formation U15 saison 2024-25 à Will
□ Will + Secrétariat : communiquer nouveaux tarifs licences (+10€) avant reprise

STRATÉGIQUE :
□ Tout le staff : identifier nouvelles sources de revenus (sponsors, loto, réderie, dîner dansant)
□ Will + Staff : sourcer + sélectionner 7 services civiques pour septembre (CV + test terrain)

MOYEN :
□ Moussa / Lenny : transmettre CV Lucas (service civique potentiel) à Will (début sept)
END_PV;

        $reunion->setPvContenu($pv);

        $this->em->persist($reunion);

        // ── Convoquer le staff élargi ─────────────────────────────────────────
        // Rôles à convoquer : DIRIGEANT, COACH, STAFF, TRESORIER, EMPLOYE
        $rolesStaff = [
            UserClubRole::ROLE_DIRIGEANT,
            UserClubRole::ROLE_COACH,
            UserClubRole::ROLE_STAFF,
            UserClubRole::ROLE_TRESORIER,
            UserClubRole::ROLE_EMPLOYE,
        ];

        $ucrs = $this->em->createQueryBuilder()
            ->select('ucr, u')
            ->from(UserClubRole::class, 'ucr')
            ->join('ucr.user', 'u')
            ->where('ucr.club = :club')
            ->andWhere('ucr.status = :active')
            ->andWhere('ucr.role IN (:roles)')
            ->setParameter('club', $club)
            ->setParameter('active', UserClubRole::STATUS_ACTIVE)
            ->setParameter('roles', $rolesStaff)
            ->getQuery()
            ->getResult();

        $usersConvoques = [];
        $nbConvocations = 0;
        foreach ($ucrs as $ucr) {
            $u = $ucr->getUser();
            if ($u !== null && !isset($usersConvoques[$u->getId()])) {
                $usersConvoques[$u->getId()] = true;
                $conv = new ReunionConvocation();
                $conv->setReunion($reunion);
                $conv->setUser($u);
                $conv->setStatut(ReunionConvocation::STATUT_PRESENT); // déjà tenue
                $this->em->persist($conv);
                $nbConvocations++;
            }
        }

        // ── Résumé + flush ────────────────────────────────────────────────────
        $io->section('Résumé de l\'import');
        $io->table(['Champ', 'Valeur'], [
            ['Titre', $titre],
            ['Date', '25 juin 2026 à 19h00'],
            ['Lieu', 'Gymnase MABB — Étouvie'],
            ['Statut', 'TENUE'],
            ['Type', 'AUTRE (réunion d\'équipe)'],
            ['PV', 'Oui (' . strlen($pv) . ' caractères)'],
            ['Convocations staff créées', (string) $nbConvocations],
        ]);

        if ($isDryRun) {
            $io->warning('--dry-run activé : aucune écriture BDD.');
            return Command::SUCCESS;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Réunion "%s" importée (ID %d) avec %d convocations staff.',
            $titre,
            $reunion->getId() ?? 0,
            $nbConvocations
        ));

        $io->text('Accessible dans Manager → /reunions');
        return Command::SUCCESS;
    }
}
