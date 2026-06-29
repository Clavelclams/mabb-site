<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Sport\BilanCompetence;
use App\Repository\Sport\BilanCompetenceRepository;
use App\Repository\Sport\JoueurRepository;
use App\Repository\Core\ClubRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importe les bilans de compétences parsés depuis les fichiers Excel de la MABB.
 *
 * Usage :
 *   php bin/console app:bilan:import --dry-run   ← preview (ne touche pas la DB)
 *   php bin/console app:bilan:import             ← import réel
 *   php bin/console app:bilan:import --force     ← réimporte même si bilan déjà existant
 *
 * Matching joueur : NOM + PRENOM (case-insensitive) OU numéro de licence.
 * Si le nom ne matche pas, le joueur est logué dans la liste "NON TROUVÉS".
 */
#[AsCommand(
    name: 'app:bilan:import',
    description: 'Importe les bilans de compétences des camps MABB (saisons 2023-2024 et 2025-2026)',
)]
class ImportBilanCommand extends Command
{
    // =========================================================================
    // DATA PARSÉE DEPUIS LES FICHIERS EXCEL (22 joueurs avec données remplies)
    // Généré le 2026-06-29 par le script Python d'extraction.
    // =========================================================================
    private const BILANS_DATA = [
        [
            'nom'    => 'Fouli',
            'prenom' => 'Sorena',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => 'BC139538',
            'allergies' => 'ACHRIEN',
            'main_forte' => 'Droite',
            'nb_seances' => 4,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 8, 'vq_discipline' => 7,
            'vq_vie_groupe' => 7, 'vq_rangement' => 8,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Darius',
            'prenom' => 'Soumeya',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 2,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 6, 'vq_discipline' => 8,
            'vq_vie_groupe' => 8, 'vq_rangement' => 7,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Mbililyama',
            'prenom' => 'Wendy',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 3,
            'vq_respect_regles' => null, 'vq_ponctualite' => 5, 'vq_discipline' => null,
            'vq_vie_groupe' => null, 'vq_rangement' => null,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Guelfat',
            'prenom' => 'Rahma',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 4,
            'vq_respect_regles' => null, 'vq_ponctualite' => 7, 'vq_discipline' => null,
            'vq_vie_groupe' => 7, 'vq_rangement' => 8,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Ahamadi',
            'prenom' => 'Laya',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 4,
            'vq_respect_regles' => 7, 'vq_ponctualite' => 5, 'vq_discipline' => 6,
            'vq_vie_groupe' => 6, 'vq_rangement' => 9,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Ahamadi',
            'prenom' => 'Tisha',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 5,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 7, 'vq_discipline' => 7,
            'vq_vie_groupe' => 8, 'vq_rangement' => 9,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Conde',
            'prenom' => 'Saran',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 7, 'vq_discipline' => 7,
            'vq_vie_groupe' => 8, 'vq_rangement' => 9,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        [
            'nom'    => 'Ahano',
            'prenom' => 'Fever',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'brouillon',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => 'Droite',
            'nb_seances' => 3,
            'vq_respect_regles' => null, 'vq_ponctualite' => 8, 'vq_discipline' => null,
            'vq_vie_groupe' => 6, 'vq_rangement' => null,
            'qm_enthousiasme' => null, 'qm_determination' => null, 'qm_confiance' => null,
            'qm_curiosite' => null, 'qm_autonomie' => null, 'qm_concentration' => null,
            'qtt_adresse' => null, 'qtt_efficacite_panier' => null, 'qtt_aisance' => null,
            'qtt_jeu_sans_ballons' => null, 'qtt_comprehension' => null, 'qtt_defense' => null,
            'qtt_rebond_catcher' => null, 'qtt_rebond_transiter' => null,
            'qp_enchainement' => null, 'qp_vitesse' => null, 'qp_soins_du_corps' => null,
            'points_forts' => null, 'alerte_medicale' => null,
            'points_vigilance' => null, 'axes_travail' => null, 'bilan_remarques' => null,
        ],
        // ── U14 (bilan U14 2K25-26.xlsx) — 22/22 critères remplis ────────────
        [
            'nom'    => 'Leite',
            'prenom' => 'Yana',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2026-06-03',
            'statut' => 'valide',
            'numero_licence' => 'BC121565',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => null,
            'vq_respect_regles' => 10, 'vq_ponctualite' => 10, 'vq_discipline' => 9,
            'vq_vie_groupe' => 9, 'vq_rangement' => 9,
            'qm_enthousiasme' => 6, 'qm_determination' => 6, 'qm_confiance' => 4,
            'qm_curiosite' => 7, 'qm_autonomie' => 7, 'qm_concentration' => 8,
            'qtt_adresse' => 5, 'qtt_efficacite_panier' => 3, 'qtt_aisance' => 3,
            'qtt_jeu_sans_ballons' => 5, 'qtt_comprehension' => 5, 'qtt_defense' => 2,
            'qtt_rebond_catcher' => 6, 'qtt_rebond_transiter' => 7,
            'qp_enchainement' => 6, 'qp_vitesse' => 3, 'qp_soins_du_corps' => 10,
            'points_forts'     => 'Arrive tjrs en avance entrainable conteste jms et est curieuse',
            'alerte_medicale'  => null,
            'points_vigilance' => 'Mentalement baisse de motivation pb à a maison peu de confiance en elle suite au sirage de banc',
            'axes_travail'     => 'Confiance en elle, tenir ses vis à vis et la finition proche du cercle',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'El Hamdaoui',
            'prenom' => 'Aya',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2026-06-03',
            'statut' => 'valide',
            'numero_licence' => 'BC126268',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => null,
            'vq_respect_regles' => 7, 'vq_ponctualite' => 5, 'vq_discipline' => 6,
            'vq_vie_groupe' => 6, 'vq_rangement' => 7,
            'qm_enthousiasme' => 7, 'qm_determination' => 5, 'qm_confiance' => 5,
            'qm_curiosite' => 4, 'qm_autonomie' => 5, 'qm_concentration' => 6,
            'qtt_adresse' => 3, 'qtt_efficacite_panier' => 4, 'qtt_aisance' => 6,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 4, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 6, 'qtt_rebond_transiter' => 5,
            'qp_enchainement' => 6, 'qp_vitesse' => 6, 'qp_soins_du_corps' => 10,
            'points_forts'     => 'Aisance avec balle à pleine vitesse mais va trop loin en dribble se fracasse dans joueuses',
            'alerte_medicale'  => null,
            'points_vigilance' => 'Entente avec ses camarades compliqué malgré son caractère gentil est en difficulté à s\'intégrer avec le groupe qui se ligue contre elle et vice versa',
            'axes_travail'     => 'Lever la tête dans le dribble faire les bons choix et travailler la finition',
            'bilan_remarques'  => null,
        ],
        // ── bilan vierge.xlsx — Camp oct 2023 (saison 2023-2024) — 22/22 ──────
        [
            'nom'    => 'Ben Salah',
            'prenom' => 'Farah',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC102531',
            'allergies' => null,
            'main_forte' => 'DROITE',
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 6, 'vq_discipline' => 7,
            'vq_vie_groupe' => 7, 'vq_rangement' => 6,
            'qm_enthousiasme' => 6, 'qm_determination' => 5, 'qm_confiance' => 5,
            'qm_curiosite' => 4, 'qm_autonomie' => 3, 'qm_concentration' => 3,
            'qtt_adresse' => 4, 'qtt_efficacite_panier' => 5, 'qtt_aisance' => 3,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 4, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 5, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 4, 'qp_vitesse' => 6, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Vitesse',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'La gestion de concentration',
            'axes_travail'     => 'L\'adresse L\'aisance de balle',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Debergues',
            'prenom' => 'Nola',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC106804',
            'allergies' => null,
            'main_forte' => 'GAUCHE',
            'nb_seances' => 3,
            'vq_respect_regles' => 9, 'vq_ponctualite' => 9, 'vq_discipline' => 9,
            'vq_vie_groupe' => 7, 'vq_rangement' => 6,
            'qm_enthousiasme' => 8, 'qm_determination' => 6, 'qm_confiance' => 5,
            'qm_curiosite' => 6, 'qm_autonomie' => 6, 'qm_concentration' => 6,
            'qtt_adresse' => 4, 'qtt_efficacite_panier' => 5, 'qtt_aisance' => 5,
            'qtt_jeu_sans_ballons' => 5, 'qtt_comprehension' => 4, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 6, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 4, 'qp_vitesse' => 6, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'L\'adresse',
            'alerte_medicale'  => 'Probleme de respiration',
            'points_vigilance' => 'Confiance et Affirmation de soi',
            'axes_travail'     => 'l\'aisance en dribble',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Dekkiche',
            'prenom' => 'Amira',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC098318',
            'allergies' => null,
            'main_forte' => 'DROITE',
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 6, 'vq_discipline' => 8,
            'vq_vie_groupe' => 7, 'vq_rangement' => 5,
            'qm_enthousiasme' => 8, 'qm_determination' => 6, 'qm_confiance' => 6,
            'qm_curiosite' => 4, 'qm_autonomie' => 4, 'qm_concentration' => 4,
            'qtt_adresse' => 5, 'qtt_efficacite_panier' => 6, 'qtt_aisance' => 4,
            'qtt_jeu_sans_ballons' => 5, 'qtt_comprehension' => 4, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 5, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 6, 'qp_vitesse' => 4, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Efficacité proche du panier',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Concentration à l\'entrainement',
            'axes_travail'     => 'Aisance utile : Dribble - Passe',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Milapie',
            'prenom' => 'Cler-mirice',
            'saison' => '2025-2026',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => null,
            'statut' => 'valide',
            'numero_licence' => 'BC107295',
            'allergies' => null,
            'main_forte' => 'DROITE',
            'nb_seances' => 3,
            'vq_respect_regles' => 9, 'vq_ponctualite' => 6, 'vq_discipline' => 7,
            'vq_vie_groupe' => 6, 'vq_rangement' => 7,
            'qm_enthousiasme' => 8, 'qm_determination' => 7, 'qm_confiance' => 8,
            'qm_curiosite' => 7, 'qm_autonomie' => 6, 'qm_concentration' => 4,
            'qtt_adresse' => 7, 'qtt_efficacite_panier' => 7, 'qtt_aisance' => 6,
            'qtt_jeu_sans_ballons' => 6, 'qtt_comprehension' => 7, 'qtt_defense' => 7,
            'qtt_rebond_catcher' => 8, 'qtt_rebond_transiter' => 8,
            'qp_enchainement' => 5, 'qp_vitesse' => 6, 'qp_soins_du_corps' => 4,
            'points_forts'     => 'Attitude Défensive',
            'alerte_medicale'  => 'Douleur au genou',
            'points_vigilance' => 'Concentration à l\'entrainement',
            'axes_travail'     => 'L\'aisance avec ballon (passe, dribble)',
            'bilan_remarques'  => 'Je vois de plus en plus une basketteuse quand je regarde Cler-mirice, je vois du basket une personne qui a soif d\'envie de progresser ! Attention à la gestion de la frustration, ça impacte parfois l\'équipe et attention dans le jeu à pas être dispersée sur ce qu\'on fait les autres (de bien ou mal) mais de rester focus sur les consignes, chacun son rôle. L\'équipe de manière générale à progresser donc plus la peine de vouloir porter l\'équipe seul occasionnellement, c\'est la sensation qu\'on ressent... La gestion de concentration à l\'entrainement quand on doit être concentré et quand on peut rigoler être dispersé',
        ],
        [
            'nom'    => 'Packa',
            'prenom' => 'Lindsay',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC092132',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 3,
            'vq_respect_regles' => 9, 'vq_ponctualite' => 7, 'vq_discipline' => 8,
            'vq_vie_groupe' => 8, 'vq_rangement' => 6,
            'qm_enthousiasme' => 7, 'qm_determination' => 5, 'qm_confiance' => 5,
            'qm_curiosite' => 4, 'qm_autonomie' => 4, 'qm_concentration' => 6,
            'qtt_adresse' => 4, 'qtt_efficacite_panier' => 4, 'qtt_aisance' => 3,
            'qtt_jeu_sans_ballons' => 3, 'qtt_comprehension' => 3, 'qtt_defense' => 6,
            'qtt_rebond_catcher' => 5, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 6, 'qp_vitesse' => 7, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Athlétique - explosivité',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Aisance utile : Dribble - Passe',
            'axes_travail'     => 'L\'aisance',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Sano',
            'prenom' => 'Genaba',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC108604',
            'allergies' => null,
            'main_forte' => 'GAUCHE',
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 6, 'vq_discipline' => 7,
            'vq_vie_groupe' => 6, 'vq_rangement' => 7,
            'qm_enthousiasme' => 7, 'qm_determination' => 6, 'qm_confiance' => 7,
            'qm_curiosite' => 5, 'qm_autonomie' => 5, 'qm_concentration' => 3,
            'qtt_adresse' => 6, 'qtt_efficacite_panier' => 7, 'qtt_aisance' => 7,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 4, 'qtt_defense' => 7,
            'qtt_rebond_catcher' => 7, 'qtt_rebond_transiter' => 8,
            'qp_enchainement' => 5, 'qp_vitesse' => 8, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Vitesse - Explosivité',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Concentration à l\'entrainement',
            'axes_travail'     => 'L\'adresse Compréhension du jeu (sens basket)',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Litongu',
            'prenom' => 'Blessing',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC104609',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 2,
            'vq_respect_regles' => 7, 'vq_ponctualite' => 6, 'vq_discipline' => 6,
            'vq_vie_groupe' => 5, 'vq_rangement' => 4,
            'qm_enthousiasme' => 6, 'qm_determination' => 4, 'qm_confiance' => 5,
            'qm_curiosite' => 4, 'qm_autonomie' => 4, 'qm_concentration' => 5,
            'qtt_adresse' => 3, 'qtt_efficacite_panier' => 4, 'qtt_aisance' => 3,
            'qtt_jeu_sans_ballons' => 3, 'qtt_comprehension' => 3, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 5, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 6, 'qp_vitesse' => 5, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Rebond - Transiter Relancer',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Aisance utile : Dribble - Passe',
            'axes_travail'     => 'Aisance utile : Dribble - Passe',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Gombert',
            'prenom' => 'Maya',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC097371',
            'allergies' => null,
            'main_forte' => 'DROITE',
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 4, 'vq_discipline' => 8,
            'vq_vie_groupe' => 8, 'vq_rangement' => 6,
            'qm_enthousiasme' => 9, 'qm_determination' => 8, 'qm_confiance' => 4,
            'qm_curiosite' => 6, 'qm_autonomie' => 6, 'qm_concentration' => 4,
            'qtt_adresse' => 6, 'qtt_efficacite_panier' => 7, 'qtt_aisance' => 6,
            'qtt_jeu_sans_ballons' => 6, 'qtt_comprehension' => 6, 'qtt_defense' => 8,
            'qtt_rebond_catcher' => 5, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 7, 'qp_vitesse' => 5, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Adresse',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Aisance utile : Dribble - Passe Frustration - Communication',
            'axes_travail'     => 'Aisance utile : Dribble - Passe',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Geto-Molisho',
            'prenom' => 'Tessia',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC098381',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 3,
            'vq_respect_regles' => 7, 'vq_ponctualite' => 4, 'vq_discipline' => 5,
            'vq_vie_groupe' => 5, 'vq_rangement' => 6,
            'qm_enthousiasme' => 6, 'qm_determination' => 5, 'qm_confiance' => 4,
            'qm_curiosite' => 5, 'qm_autonomie' => 5, 'qm_concentration' => 5,
            'qtt_adresse' => 5, 'qtt_efficacite_panier' => 6, 'qtt_aisance' => 4,
            'qtt_jeu_sans_ballons' => 5, 'qtt_comprehension' => 4, 'qtt_defense' => 3,
            'qtt_rebond_catcher' => 7, 'qtt_rebond_transiter' => 7,
            'qp_enchainement' => 4, 'qp_vitesse' => 5, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Percussion',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Aisance utile : Dribble - Passe Attitude Défensive (posture - gestion des duels)',
            'axes_travail'     => 'Aisance utile : Dribble - Passe',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Eziorah',
            'prenom' => 'Pandora',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC092194',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 5, 'vq_discipline' => 7,
            'vq_vie_groupe' => 9, 'vq_rangement' => 5,
            'qm_enthousiasme' => 7, 'qm_determination' => 7, 'qm_confiance' => 6,
            'qm_curiosite' => 5, 'qm_autonomie' => 5, 'qm_concentration' => 6,
            'qtt_adresse' => 4, 'qtt_efficacite_panier' => 6, 'qtt_aisance' => 5,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 4, 'qtt_defense' => 7,
            'qtt_rebond_catcher' => 8, 'qtt_rebond_transiter' => 7,
            'qp_enchainement' => 3, 'qp_vitesse' => 6, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Rebond - Catcher Remonter',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Adresse  - Efficacité des choix de tirs Enchainement de l\'effort Jeu sans ballons',
            'axes_travail'     => 'Jeu sans ballons Adresse  - Efficacité des choix de tirs',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Dambeti',
            'prenom' => 'Chloe',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => 'BC108636',
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 3,
            'vq_respect_regles' => 8, 'vq_ponctualite' => 6, 'vq_discipline' => 7,
            'vq_vie_groupe' => 7, 'vq_rangement' => 5,
            'qm_enthousiasme' => 7, 'qm_determination' => 6, 'qm_confiance' => 6,
            'qm_curiosite' => 4, 'qm_autonomie' => 4, 'qm_concentration' => 5,
            'qtt_adresse' => 3, 'qtt_efficacite_panier' => 4, 'qtt_aisance' => 4,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 3, 'qtt_defense' => 6,
            'qtt_rebond_catcher' => 6, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 5, 'qp_vitesse' => 7, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Vitesse - Explosivité',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Adresse  - Efficacité des choix de tirs Compréhension et concentration',
            'axes_travail'     => 'Adresse  - Efficacité des choix de tirs',
            'bilan_remarques'  => null,
        ],
        [
            'nom'    => 'Hrifa',
            'prenom' => 'Ibtissem',
            'saison' => '2023-2024',
            'contexte' => 'Camp d\'entraînement',
            'date_evaluation' => '2023-10-20',
            'statut' => 'valide',
            'numero_licence' => null,
            'allergies' => null,
            'main_forte' => null,
            'nb_seances' => 3,
            'vq_respect_regles' => 7, 'vq_ponctualite' => 5, 'vq_discipline' => 5,
            'vq_vie_groupe' => 7, 'vq_rangement' => 4,
            'qm_enthousiasme' => 6, 'qm_determination' => 4, 'qm_confiance' => 5,
            'qm_curiosite' => 4, 'qm_autonomie' => 5, 'qm_concentration' => 5,
            'qtt_adresse' => 4, 'qtt_efficacite_panier' => 5, 'qtt_aisance' => 5,
            'qtt_jeu_sans_ballons' => 4, 'qtt_comprehension' => 4, 'qtt_defense' => 5,
            'qtt_rebond_catcher' => 6, 'qtt_rebond_transiter' => 6,
            'qp_enchainement' => 5, 'qp_vitesse' => 5, 'qp_soins_du_corps' => 5,
            'points_forts'     => 'Efficacité proche du panier',
            'alerte_medicale'  => 'Rien à signaler',
            'points_vigilance' => 'Détermination / Goût de l\'effort',
            'axes_travail'     => 'Adresse  - Efficacité des choix de tirs',
            'bilan_remarques'  => null,
        ],
    ];

    public function __construct(
        private readonly JoueurRepository          $joueurRepo,
        private readonly BilanCompetenceRepository $bilanRepo,
        private readonly ClubRepository            $clubRepo,
        private readonly EntityManagerInterface    $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview sans écrire en DB')
            ->addOption('force',   null, InputOption::VALUE_NONE, 'Réimporte même si bilan déjà existant (sur même joueur+saison+contexte)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $force  = $input->getOption('force');

        $io->title('Import bilans de compétences MABB');
        $io->note($dryRun ? '🔍 MODE DRY-RUN — aucune écriture DB' : '✍️  MODE RÉEL — écriture en DB');

        // ── 1. Trouver le club ───────────────────────────────────────────────────
        // Priorité : sigle → nom exact → fallback substring
        $club = $this->clubRepo->findOneBy(['sigle' => 'MABB'])
             ?? $this->clubRepo->findOneBy(['nom'   => 'Amiens Métropole Basket-Ball']);
        if ($club === null) {
            foreach ($this->clubRepo->findAll() as $c) {
                $up = strtoupper($c->getNom() ?? '');
                if (str_contains($up, 'AMIENS') || str_contains($up, 'MABB')) {
                    $club = $c;
                    break;
                }
            }
        }
        if ($club === null) {
            $all = implode(', ', array_map(
                fn($c) => sprintf('"%s" (sigle: %s)', $c->getNom(), $c->getSigle() ?? '—'),
                $this->clubRepo->findAll()
            ));
            $io->error("Club introuvable. Clubs en DB : $all");
            return Command::FAILURE;
        }
        $io->success(sprintf('Club trouvé : %s (id=%d)', $club->getNom(), $club->getId()));

        // ── 2. Charger tous les joueurs du club en mémoire ──────────────────
        $allJoueurs = $this->joueurRepo->findBy(['club' => $club]);

        // Index par "NOM PRENOM" normalisé + par licence
        $byName    = [];
        $byLicence = [];
        foreach ($allJoueurs as $j) {
            $key = $this->normalise($j->getNom() ?? '') . '|' . $this->normalise($j->getPrenom() ?? '');
            $byName[$key] = $j;
            // Aussi par licence
            if ($j->getLicence()) {
                $byLicence[strtoupper(trim($j->getLicence()))] = $j;
            }
        }

        // ── 3. Boucle import ────────────────────────────────────────────────
        $matched = 0;
        $skipped = 0;
        $notFound = [];
        $inserted = 0;

        foreach (self::BILANS_DATA as $row) {
            $nom    = $row['nom']    ?? '';
            $prenom = $row['prenom'] ?? '';
            $label  = "$prenom $nom ({$row['saison']})";

            // Cherche par NOM+PRENOM
            $joueur = $byName[$this->normalise($nom) . '|' . $this->normalise($prenom)] ?? null;

            // Fallback : licence
            if ($joueur === null && !empty($row['numero_licence'])) {
                $joueur = $byLicence[strtoupper(trim($row['numero_licence']))] ?? null;
            }

            // Fallback : NOM only (si prénom approx)
            if ($joueur === null) {
                foreach ($allJoueurs as $j) {
                    if ($this->normalise($j->getNom() ?? '') === $this->normalise($nom)) {
                        $joueur = $j;
                        break;
                    }
                }
            }

            if ($joueur === null) {
                $notFound[] = "$prenom $nom (licence: " . ($row['numero_licence'] ?? '—') . ", saison: {$row['saison']})";
                $io->warning("NON TROUVÉ : $label");
                continue;
            }

            $matched++;

            // Vérifier doublon
            if (!$force) {
                $existing = $this->bilanRepo->findOneBy([
                    'joueur'   => $joueur,
                    'saison'   => $row['saison'],
                    'contexte' => $row['contexte'],
                ]);
                if ($existing !== null) {
                    $io->text("  <info>⏭ SKIP</info> $label — bilan déjà existant (id={$existing->getId()})");
                    $skipped++;
                    continue;
                }
            }

            $io->text("  <info>✓ MATCH</info> $label → {$joueur->getNomComplet()} (id={$joueur->getId()})");

            if (!$dryRun) {
                $bilan = $this->hydrateBilan($row, $joueur, $club);
                $this->em->persist($bilan);
                $inserted++;
            } else {
                $inserted++;
            }
        }

        if (!$dryRun && $inserted > 0) {
            $this->em->flush();
        }

        // ── 4. Rapport final ─────────────────────────────────────────────────
        $io->section('Résultat');
        $io->definitionList(
            ['Total dans le fichier' => count(self::BILANS_DATA)],
            ['Matchés en DB'         => $matched],
            ['Insérés'               => $dryRun ? "$inserted (dry-run, pas écrits)" : $inserted],
            ['Skippés (doublons)'    => $skipped],
            ['Non trouvés'           => count($notFound)],
        );

        if (!empty($notFound)) {
            $io->section('Joueurs non trouvés en DB — à créer manuellement ou noms à corriger :');
            foreach ($notFound as $nf) {
                $io->text("  • $nf");
            }
            $io->note('Ces joueuses ne sont peut-être pas encore créées dans Manager, ou le nom dans l\'Excel diffère légèrement de la DB.');
        }

        if ($dryRun) {
            $io->note('Dry-run terminé. Relance sans --dry-run pour importer réellement.');
        } else {
            $io->success(sprintf('%d bilan(s) importé(s) avec succès.', $inserted));
        }

        return Command::SUCCESS;
    }

    private function hydrateBilan(array $row, $joueur, $club): BilanCompetence
    {
        $b = new BilanCompetence();
        $b->setJoueur($joueur)
          ->setClub($club)
          ->setSaison($row['saison'])
          ->setContexte($row['contexte'] ?? null)
          ->setStatut($row['statut'] ?? 'brouillon')
          ->setNumeroLicence($row['numero_licence'] ?? null)
          ->setAllergies($row['allergies'] ?? null)
          ->setMainForte($this->normaliseMainForte($row['main_forte'] ?? null))
          ->setNbSeances($row['nb_seances'] ?? null);

        if (!empty($row['date_evaluation'])) {
            try {
                $b->setDateEvaluation(new \DateTimeImmutable($row['date_evaluation']));
            } catch (\Throwable) {}
        }

        // Scores VQ
        $b->setVqRespectRegles($row['vq_respect_regles'] ?? null)
          ->setVqPonctualite($row['vq_ponctualite'] ?? null)
          ->setVqDiscipline($row['vq_discipline'] ?? null)
          ->setVqVieGroupe($row['vq_vie_groupe'] ?? null)
          ->setVqRangement($row['vq_rangement'] ?? null);

        // Scores QM
        $b->setQmEnthousiasme($row['qm_enthousiasme'] ?? null)
          ->setQmDetermination($row['qm_determination'] ?? null)
          ->setQmConfiance($row['qm_confiance'] ?? null)
          ->setQmCuriosite($row['qm_curiosite'] ?? null)
          ->setQmAutonomie($row['qm_autonomie'] ?? null)
          ->setQmConcentration($row['qm_concentration'] ?? null);

        // Scores QTT
        $b->setQttAdresse($row['qtt_adresse'] ?? null)
          ->setQttEfficacitePanier($row['qtt_efficacite_panier'] ?? null)
          ->setQttAisance($row['qtt_aisance'] ?? null)
          ->setQttJeuSansBallons($row['qtt_jeu_sans_ballons'] ?? null)
          ->setQttComprehension($row['qtt_comprehension'] ?? null)
          ->setQttDefense($row['qtt_defense'] ?? null)
          ->setQttRebondCatcher($row['qtt_rebond_catcher'] ?? null)
          ->setQttRebondTransiter($row['qtt_rebond_transiter'] ?? null);

        // Scores QP
        $b->setQpEnchainement($row['qp_enchainement'] ?? null)
          ->setQpVitesse($row['qp_vitesse'] ?? null)
          ->setQpSoinsDuCorps($row['qp_soins_du_corps'] ?? null);

        // Texte
        $b->setPointsForts($row['points_forts'] ?? null)
          ->setAlerteMedicale($row['alerte_medicale'] ?? null)
          ->setPointsVigilance($row['points_vigilance'] ?? null)
          ->setAxesTravail($row['axes_travail'] ?? null)
          ->setBilanRemarques($row['bilan_remarques'] ?? null);

        return $b;
    }

    /** Normalise un nom pour comparaison : minuscule, sans accents, trim. */
    private function normalise(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Supprimer accents
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        // Supprimer tirets et espaces multiples
        $s = preg_replace('/[-\s]+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Normalise la main forte en DROITE/GAUCHE/AMBIDEXTRE. */
    private function normaliseMainForte(?string $v): ?string
    {
        if ($v === null) return null;
        $v = mb_strtoupper(trim($v));
        if (str_contains($v, 'DROIT')) return 'DROITE';
        if (str_contains($v, 'GAUCH')) return 'GAUCHE';
        if (str_contains($v, 'AMBI'))  return 'AMBIDEXTRE';
        return null;
    }
}
